<?php

namespace App\Http\Controllers\Ui;

use App\Couriers\AccountSettings;
use App\Couriers\CourierAccountException;
use App\Couriers\CourierAccounts;
use App\Couriers\CourierRegistry;
use App\Couriers\TrackingNumbers\TrackingNumberSeriesService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ui\SaveCourierAccountRequest;
use App\Models\CourierAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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

    /**
     * The account page. A courier without an account shows the same form (the toggle on Sandbox, that environment's
     * keys, booking defaults); its first Save creates the account. The webhook address and LBC number blocks need the
     * account, so they appear after that.
     */
    public function show(string $courier, CourierAccounts $accounts): View
    {
        $account = $accounts->forCourier($courier);
        $settings = $account ? AccountSettings::for($account) : null;
        $withValue = fn (array $f) => $f + ['value' => $settings?->read($f['key'])];
        $plain = $accounts->plainFields($account ?? $courier);

        return view('accounts.show', [
            'courier' => $courier,
            'account' => $account,
            'accountFields' => array_map($withValue, array_values(array_filter($plain, fn (array $f) => ($f['section'] ?? null) === 'account'))),
            'fields' => array_map($withValue, array_values(array_filter($plain, fn (array $f) => ($f['section'] ?? null) !== 'account'))),
            'environments' => $this->environments($courier, $account, $accounts, $settings),
            'webhook' => $account ? $accounts->webhookAddress($account, true) : null,
            'blocks' => $account?->courier === 'lbc' ? app(TrackingNumberSeriesService::class)->blockRows($account) : null,
        ]);
    }

    private function account(string $courier, CourierAccounts $accounts): CourierAccount
    {
        return $accounts->forCourier($courier) ?? abort(404);
    }

    /**
     * Save, and Save and test (`after=test`). With no account yet, the first save creates it (CourierAccounts::setUp:
     * one default account named after the courier, under the set-up lock) and stores the keys in the same
     * transaction, so a refused value leaves no account behind.
     */
    public function update(SaveCourierAccountRequest $request, string $courier, CourierAccounts $accounts): RedirectResponse
    {
        abort_unless(CourierRegistry::has($courier), 404);
        $data = $request->validated();
        $actor = ['type' => 'admin', 'id' => $request->user()->getAuthIdentifier(), 'ip' => $request->ip()];
        $save = fn (CourierAccount $account) => $this->save($account, $data, $accounts, $actor);

        try {
            $account = $accounts->forCourier($courier);
            if ($account === null) {
                $account = $accounts->setUp($courier, $data['mode'], $actor, $save);
            } else {
                DB::transaction(fn () => $save($account));
            }
        } catch (ValidationException $e) {
            return back()->withInput($request->except('secrets'))->withErrors($e->errors());
        } catch (CourierAccountException $e) {
            // Set up refused (another set up running, or the account appeared meanwhile).
            return back()->withInput($request->except('secrets'))->withErrors(['mode' => $e->getMessage()]);
        }

        return $request->wantsTest()
            ? $this->testResult($courier, $accounts->test($account), 'Saved')
            : redirect(route('accounts.show', $courier))->with('status', 'Saved');
    }

    /**
     * Writes the environment, the changed plain settings and the typed secrets. Every refusal is collected and thrown
     * together as a ValidationException, which rolls the caller's transaction back.
     *
     * @param  array<string, mixed>  $data
     * @param  array{type: string, id: mixed, ip: ?string}  $actor
     */
    private function save(CourierAccount $account, array $data, CourierAccounts $accounts, array $actor): void
    {
        $errors = [];
        $attempt = function (string $field, callable $do) use (&$errors): void {
            try {
                $do();
            } catch (CourierAccountException $e) {
                $errors[$field] = $e->getMessage();
            }
        };

        $attempt('mode', fn () => $accounts->setMode($account, $data['mode'], $actor));

        // Plain fields only: booking defaults, account details and both environments' plain IDs. Secrets have their own
        // masked controls below.
        $current = AccountSettings::for($account);
        $plain = collect($accounts->schema($account))->reject(fn (array $f) => CourierAccounts::isSecretField($f))->keyBy('key');
        foreach ($data['settings'] ?? [] as $key => $value) {
            $field = $plain->get($key);
            if ($field === null) {
                continue;
            }
            $value = trim((string) $value);
            if ($this->unchanged($field, $value, (string) $current->read($key, ''))) {
                continue;
            }
            $attempt("settings.{$key}", fn () => $value === ''
                ? $accounts->clearSetting($account, $key, $actor)
                : $accounts->setSetting($account, $key, $value, $actor));
        }

        // A saved secret's input has a name only while it is being edited; an unset secret is an empty input that is
        // always posted. Blanks and anything that is not one of this courier's encrypted keys are ignored.
        $secretKeys = array_column($this->secretFields($account, $accounts), 'key');
        foreach ($data['secrets'] ?? [] as $key => $value) {
            $value = trim((string) $value);
            if ($value === '' || ! in_array($key, $secretKeys, true) || $value === $current->secret($key)) {
                continue;
            }
            $attempt("secrets.{$key}", fn () => $accounts->setSetting($account, $key, $value, $actor));
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Whether a posted plain value is what is stored. A JSON setting is compared in its compact form, so the
     * pretty-printed code box posted back unchanged writes nothing.
     *
     * @param  array<string, mixed>  $field
     */
    private function unchanged(array $field, string $value, string $stored): bool
    {
        if ($value === $stored) {
            return true;
        }
        if (($field['type'] ?? null) !== 'json' || $value === '' || $stored === '') {
            return false;
        }
        $posted = CourierAccounts::canonicalJson($value);

        return $posted !== null && $posted === CourierAccounts::canonicalJson($stored);
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
        return $this->testResult($courier, $accounts->test($this->account($courier, $accounts)));
    }

    /**
     * A connection check's answer, as Test connection shows it. After a save the status says so too.
     *
     * @param  array{ok: bool, message: string}  $result
     */
    private function testResult(string $courier, array $result, ?string $saved = null): RedirectResponse
    {
        $back = redirect(route('accounts.show', $courier));
        if ($result['ok']) {
            return $back->with('status', $saved ? $saved . ' · Connected' : 'Connected');
        }
        if ($saved) {
            $back->with('status', $saved);
        }

        return $back->withErrors(['test' => 'Check failed: ' . $result['message']]);
    }

    /**
     * Both environments' credentials, in schema order. A secret carries its length and last 4 characters only; a
     * plain field (SPX's App ID and User ID) its value. Without an account every value is empty.
     *
     * @return array<string, array<int, array{key: string, label: string, secret: bool, masked?: ?array{length: int, last4: string}, value?: ?string}>>
     */
    private function environments(string $courier, ?CourierAccount $account, CourierAccounts $accounts, ?AccountSettings $settings): array
    {
        $groups = [];
        foreach (CourierAccounts::ENVIRONMENTS as $env) {
            $groups[$env] = array_map(fn (array $f) => ['key' => $f['key'], 'label' => (string) ($f['label'] ?? $f['key']), 'secret' => CourierAccounts::isSecretField($f)]
                + (CourierAccounts::isSecretField($f)
                    ? ['masked' => $account ? $accounts->masked($account, $f['key']) : null]
                    : ['value' => $settings?->read($f['key'])]),
                $accounts->environmentFields($account ?? $courier, $env));
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
