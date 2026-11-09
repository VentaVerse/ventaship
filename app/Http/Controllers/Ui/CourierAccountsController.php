<?php

namespace App\Http\Controllers\Ui;

use App\Couriers\AccountSettings;
use App\Couriers\CourierAccountException;
use App\Couriers\CourierAccounts;
use App\Couriers\CourierRegistry;
use App\Couriers\TrackingNumbers\TrackingNumberSeriesService;
use App\Http\Controllers\Controller;
use App\Models\CourierAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CourierAccountsController extends Controller
{
    private const ORDER = ['quadx', 'spx', 'lbc'];

    public function index(CourierAccounts $accounts): View
    {
        return view('accounts.index', [
            'rows' => array_map(fn (string $c) => ['courier' => $c, 'account' => $accounts->forCourier($c)], self::ORDER),
        ]);
    }

    public function setup(Request $request, string $courier, CourierAccounts $accounts): RedirectResponse
    {
        abort_unless(CourierRegistry::has($courier), 404);
        $data = $request->validate(['mode' => ['required', 'string', Rule::in(CourierAccounts::ENVIRONMENTS)]]);

        try {
            $accounts->setUp($courier, $data['mode'], ['type' => 'admin', 'id' => $request->user()->getAuthIdentifier(), 'ip' => $request->ip()]);
        } catch (CourierAccountException $e) {
            return back()->withInput()->withErrors(['mode' => $e->getMessage()]);
        }

        return redirect(route('accounts.show', $courier))->with('status', 'Ready');
    }

    public function show(string $courier, CourierAccounts $accounts): View
    {
        $account = $accounts->forCourier($courier);
        if ($account === null) {
            return view('accounts.show', ['courier' => $courier, 'account' => null]);
        }

        $settings = AccountSettings::for($account);
        $withValue = fn (array $f) => $f + ['value' => $settings->read($f['key'])];
        $plain = $accounts->plainFields($account);

        return view('accounts.show', [
            'courier' => $courier,
            'account' => $account,
            'accountFields' => array_map($withValue, array_values(array_filter($plain, fn (array $f) => ($f['section'] ?? null) === 'account'))),
            'fields' => array_map($withValue, array_values(array_filter($plain, fn (array $f) => ($f['section'] ?? null) !== 'account'))),
            'environments' => $this->environments($account, $accounts, $settings),
            'webhook' => $accounts->webhookAddress($account, true),
            'blocks' => $account->courier === 'lbc' ? app(TrackingNumberSeriesService::class)->blockRows($account) : null,
        ]);
    }

    private function account(string $courier, CourierAccounts $accounts): CourierAccount
    {
        return $accounts->forCourier($courier) ?? abort(404);
    }

    public function update(Request $request, string $courier, CourierAccounts $accounts): RedirectResponse
    {
        $account = $this->account($courier, $accounts);
        $data = $request->validate([
            'mode' => ['required', 'string', Rule::in(CourierAccounts::ENVIRONMENTS)],
            'settings' => ['nullable', 'array'],
            'settings.*' => ['nullable', 'string'],
            'secrets' => ['nullable', 'array'],
            'secrets.*' => ['nullable', 'string', 'max:' . CourierAccounts::MAX_VALUE_LENGTH],
        ]);

        $actor = ['type' => 'admin', 'id' => $request->user()->getAuthIdentifier(), 'ip' => $request->ip()];
        $errors = [];
        $attempt = function (string $field, callable $do) use (&$errors): void {
            try {
                $do();
            } catch (CourierAccountException $e) {
                $errors[$field] = $e->getMessage();
            }
        };

        DB::beginTransaction();
        try {
            $attempt('mode', fn () => $accounts->setMode($account, $data['mode'], $actor));

            // Plain fields only: booking defaults, account details and both environments' plain IDs. Secrets have
            // their own masked controls below.
            $current = AccountSettings::for($account);
            $plain = array_column(array_filter($accounts->schema($account), fn (array $f) => ! CourierAccounts::isSecretField($f)), 'key');
            foreach ($data['settings'] ?? [] as $key => $value) {
                if (! in_array($key, $plain, true)) {
                    continue;
                }
                $value = trim((string) $value);
                $stored = (string) $current->read($key, '');
                if ($value === $stored) {
                    continue;
                }
                $attempt("settings.{$key}", fn () => $value === ''
                    ? $accounts->clearSetting($account, $key, $actor)
                    : $accounts->setSetting($account, $key, $value, $actor));
            }

            // Only an edited key arrives (an untouched masked field has no name); blanks and anything that is not one of
            // this courier's encrypted keys are ignored.
            $secretKeys = array_column($this->secretFields($account, $accounts), 'key');
            foreach ($data['secrets'] ?? [] as $key => $value) {
                $value = trim((string) $value);
                if ($value === '' || ! in_array($key, $secretKeys, true) || $value === $current->secret($key)) {
                    continue;
                }
                $attempt("secrets.{$key}", fn () => $accounts->setSetting($account, $key, $value, $actor));
            }
        } catch (\Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        if ($errors !== []) {
            DB::rollBack();

            return back()->withInput($request->except('secrets'))->withErrors($errors);
        }

        DB::commit();

        return redirect(route('accounts.show', $courier))->with('status', 'Saved');
    }

    /** One stored secret, for the View button. Throttled per admin by the route; audited with the key name only. */
    public function reveal(Request $request, string $courier, string $key, CourierAccounts $accounts): JsonResponse
    {
        $account = $this->account($courier, $accounts);
        $actor = ['type' => 'admin', 'id' => $request->user()->getAuthIdentifier(), 'ip' => $request->ip()];
        $webhook = $key === CourierAccounts::WEBHOOK_KEY;
        abort_unless($webhook || in_array($key, array_column($this->secretFields($account, $accounts), 'key'), true), 404);

        try {
            $value = $webhook ? $accounts->revealWebhookAddress($account, $actor) : $accounts->revealSecret($account, $key, $actor);
        } catch (CourierAccountException) {
            abort(404);
        }

        return response()->json(['value' => $value])->withHeaders(['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
    }

    public function series(Request $request, string $courier, CourierAccounts $accounts): RedirectResponse
    {
        $account = $this->account($courier, $accounts);
        abort_unless($account->courier === 'lbc', 404);
        $data = $request->validate(['first' => ['required', 'string', 'max:20'], 'last' => ['required', 'string', 'max:20']]);

        try {
            $accounts->addLbcSeries($account, $data['first'], $data['last'], ['type' => 'admin', 'id' => $request->user()->getAuthIdentifier(), 'ip' => $request->ip()]);
        } catch (CourierAccountException $e) {
            return back()->withInput()->withErrors(['first' => $e->getMessage()]);
        }

        return redirect(route('accounts.show', $courier))->with('status', 'Added');
    }

    public function test(string $courier, CourierAccounts $accounts): RedirectResponse
    {
        $account = $this->account($courier, $accounts);
        $result = $accounts->test($account);
        $back = redirect(route('accounts.show', $courier));

        return $result['ok']
            ? $back->with('status', 'Connected')
            : $back->withErrors(['test' => 'Check failed: ' . $result['message']]);
    }

    /**
     * Both environments' credentials, in schema order. A secret carries its length and last 4 characters only; a
     * plain field (SPX's App ID and User ID) its value.
     *
     * @return array<string, array<int, array{key: string, label: string, secret: bool, masked?: ?array{length: int, last4: string}, value?: ?string}>>
     */
    private function environments(CourierAccount $account, CourierAccounts $accounts, AccountSettings $settings): array
    {
        $groups = [];
        foreach (CourierAccounts::ENVIRONMENTS as $env) {
            $groups[$env] = array_map(fn (array $f) => ['key' => $f['key'], 'label' => (string) ($f['label'] ?? $f['key']), 'secret' => CourierAccounts::isSecretField($f)]
                + (CourierAccounts::isSecretField($f) ? ['masked' => $accounts->masked($account, $f['key'])] : ['value' => $settings->read($f['key'])]),
                $accounts->environmentFields($account, $env));
        }

        return $groups;
    }

    /**
     * This courier's encrypted keys, the only ones the Keys section shows, saves or reveals.
     *
     * @return array<int, array<string, mixed>>
     */
    private function secretFields(CourierAccount $account, CourierAccounts $accounts): array
    {
        return array_values(array_filter($accounts->schema($account), fn (array $f) => ! empty($f['encrypted'])));
    }
}
