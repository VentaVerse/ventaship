<?php

namespace App\Http\Controllers\Ui;

use App\Couriers\AccountSettings;
use App\Couriers\CourierAccountException;
use App\Couriers\CourierAccounts;
use App\Couriers\CourierRegistry;
use App\Couriers\TrackingNumbers\TrackingNumberSeriesService;
use App\Http\Controllers\Controller;
use App\Models\CourierAccount;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CourierAccountsController extends Controller
{
    public function index(): View
    {
        $accounts = CourierAccount::query()->orderBy('courier')->orderByDesc('is_default')->orderBy('label')->get();
        $connected = $accounts->pluck('courier')->unique()->all();

        return view('accounts.index', [
            'accounts' => $accounts,
            'unconnected' => array_values(array_diff(CourierRegistry::keys(), $connected)),
        ]);
    }

    public function create(Request $request): View
    {
        $courier = (string) $request->query('courier');

        return view('accounts.create', [
            'couriers' => CourierRegistry::keys(),
            'courier' => CourierRegistry::has($courier) ? $courier : null,
            'modes' => CourierAccounts::CREATE_MODES,
        ]);
    }

    public function store(Request $request, CourierAccounts $accounts): RedirectResponse
    {
        $data = $request->validate([
            'courier' => ['required', 'string', Rule::in(CourierRegistry::keys())],
            'label' => ['required', 'string', 'max:255'],
            'mode' => ['required', 'string', Rule::in(CourierAccounts::CREATE_MODES)],
            'default' => ['nullable', 'boolean'],
        ]);

        try {
            $account = $accounts->create(
                $data['courier'], $data['label'], $data['mode'], (bool) ($data['default'] ?? false),
                ['type' => 'admin', 'id' => $request->user()->getAuthIdentifier(), 'ip' => $request->ip()],
            );
        } catch (CourierAccountException $e) {
            return back()->withInput()->withErrors(['label' => $e->getMessage()]);
        }

        return redirect(route('accounts.show', $account))->with('status', 'Connected');
    }

    public function show(CourierAccount $account, CourierAccounts $accounts): View
    {
        $settings = AccountSettings::for($account);
        $fields = array_map(fn (array $f) => $f + ['value' => $settings->read($f['key'])], $accounts->plainFields($account));

        return view('accounts.show', [
            'account' => $account,
            'modes' => CourierAccounts::MODES[$account->courier] ?? [],
            'fields' => $fields,
            'hasLatch' => in_array($account->courier, ['lbc', 'spx'], true),
            'keyGroups' => $this->keyGroups($account, $accounts),
            'webhook' => in_array($account->courier, ['quadx', 'spx'], true) ? [
                'base' => app(Settings::class)->publicBaseUrl(),
                'masked' => $accounts->webhookAddress($account, true),
            ] : null,
            'blocks' => $account->courier === 'lbc' ? app(TrackingNumberSeriesService::class)->blockRows($account) : null,
        ]);
    }

    public function update(Request $request, CourierAccount $account, CourierAccounts $accounts): RedirectResponse
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:255'],
            'mode' => ['required', 'string', Rule::in(CourierAccounts::MODES[$account->courier] ?? [])],
            'default' => ['nullable', 'boolean'],
            'active' => ['nullable', 'boolean'],
            'allow_live_booking' => ['nullable', 'boolean'],
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
        $attempt('label', fn () => $accounts->rename($account, $data['label'], $actor));
        $attempt('mode', fn () => $accounts->setMode($account, $data['mode'], $actor));
        if (array_key_exists('default', $data) && $data['default'] !== null) {
            $attempt('default', fn () => $accounts->setDefault($account, (bool) $data['default'], $actor));
        }
        if (array_key_exists('active', $data) && $data['active'] !== null) {
            $attempt('active', fn () => $accounts->setActive($account, (bool) $data['active'], $actor));
        }
        if (in_array($account->courier, ['lbc', 'spx'], true) && ($data['allow_live_booking'] ?? null) !== null) {
            $attempt('allow_live_booking', fn () => $accounts->setAllowLiveBooking($account, (bool) $data['allow_live_booking'], $actor));
        }

        // Only plain booking-default fields are writable here; secrets and mode-scoped keys have their own controls.
        $current = AccountSettings::for($account);
        $plain = array_column($accounts->plainFields($account), 'key');
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

        if ($errors !== []) {
            DB::rollBack();

            return back()->withInput($request->except('secrets'))->withErrors($errors);
        }

        DB::commit();

        return redirect(route('accounts.show', $account))->with('status', 'Saved');
    }

    /** One stored secret, for the View button. Throttled per admin by the route; audited with the key name only. */
    public function reveal(Request $request, CourierAccount $account, string $key, CourierAccounts $accounts): JsonResponse
    {
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

    public function series(Request $request, CourierAccount $account, CourierAccounts $accounts): RedirectResponse
    {
        abort_unless($account->courier === 'lbc', 404);
        $data = $request->validate(['first' => ['required', 'string', 'max:20'], 'last' => ['required', 'string', 'max:20']]);

        try {
            $accounts->addLbcSeries($account, $data['first'], $data['last'], ['type' => 'admin', 'id' => $request->user()->getAuthIdentifier(), 'ip' => $request->ip()]);
        } catch (CourierAccountException $e) {
            return back()->withInput()->withErrors(['first' => $e->getMessage()]);
        }

        return redirect(route('accounts.show', $account))->with('status', 'Added');
    }

    public function test(CourierAccount $account, CourierAccounts $accounts): RedirectResponse
    {
        $result = $accounts->test($account);
        $back = redirect(route('accounts.show', $account));

        return $result['ok']
            ? $back->with('status', 'Connected')
            : $back->withErrors(['test' => 'Check failed: ' . $result['message']]);
    }

    /** Which mode-scoped key set each account mode uses. A courier not listed has keys that ignore the mode. */
    private const KEY_SETS = [
        'quadx' => ['staging' => 'staging', 'production' => 'production'],
        'lbc' => ['staging' => 'test', 'production' => 'live'],
    ];

    /**
     * The Keys section: one group per mode (or a single group, mode null, when the keys ignore the mode). Each key
     * carries its length and last 4 characters only.
     *
     * @return array<int, array{mode: ?string, scope?: string, keys: array<int, array{key: string, label: string, masked: ?array{length: int, last4: string}}>}>
     */
    private function keyGroups(CourierAccount $account, CourierAccounts $accounts): array
    {
        $describe = fn (array $f) => ['key' => $f['key'], 'label' => (string) ($f['label'] ?? $f['key']), 'masked' => $accounts->masked($account, $f['key'])];
        $fields = $this->secretFields($account, $accounts);
        $sets = self::KEY_SETS[$account->courier] ?? null;

        if ($sets === null) {
            return $fields === [] ? [] : [['mode' => null, 'keys' => array_map($describe, $fields)]];
        }

        $groups = [];
        foreach ($sets as $mode => $scope) {
            $keys = array_values(array_filter($fields, fn (array $f) => ($f['mode_scoped'] ?? null) === $scope));
            if ($keys !== []) {
                $groups[] = ['mode' => $mode, 'scope' => $scope, 'keys' => array_map($describe, $keys)];
            }
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
