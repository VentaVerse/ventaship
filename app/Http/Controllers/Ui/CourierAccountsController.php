<?php

namespace App\Http\Controllers\Ui;

use App\Couriers\AccountSettings;
use App\Couriers\CourierAccountException;
use App\Couriers\CourierAccounts;
use App\Couriers\CourierRegistry;
use App\Http\Controllers\Controller;
use App\Models\CourierAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        $fields = collect($accounts->schema($account))
            ->reject(fn (array $f) => ! empty($f['encrypted']) || ! empty($f['mode_scoped']) || $f['key'] === 'lbc_mode')
            ->map(fn (array $f) => $f + ['value' => $settings->read($f['key'])])
            ->values()->all();

        return view('accounts.show', [
            'account' => $account,
            'modes' => CourierAccounts::MODES[$account->courier] ?? [],
            'fields' => $fields,
            'hasLatch' => in_array($account->courier, ['lbc', 'spx'], true),
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
        $plain = collect($accounts->schema($account))
            ->reject(fn (array $f) => ! empty($f['encrypted']) || ! empty($f['mode_scoped']) || $f['key'] === 'lbc_mode')
            ->pluck('key')->all();
        foreach ($data['settings'] ?? [] as $key => $value) {
            if (! in_array($key, $plain, true)) {
                continue;
            }
            $value = trim((string) $value);
            $stored = (string) $current->read($key, '');
            if ($value === $stored || ($value === '' && $stored === '')) {
                continue;
            }
            $attempt("settings.{$key}", fn () => $accounts->setSetting($account, $key, $value, $actor));
        }

        if ($errors !== []) {
            return back()->withInput()->withErrors($errors);
        }

        return redirect(route('accounts.show', $account))->with('status', 'Saved');
    }

    public function test(CourierAccount $account, CourierAccounts $accounts): RedirectResponse
    {
        $result = $accounts->test($account);
        $back = redirect(route('accounts.show', $account));

        return $result['ok']
            ? $back->with('status', 'Connected')
            : $back->withErrors(['test' => 'Check failed: ' . $result['message']]);
    }
}
