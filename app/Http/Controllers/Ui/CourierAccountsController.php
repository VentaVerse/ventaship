<?php

namespace App\Http\Controllers\Ui;

use App\Couriers\CourierAccountException;
use App\Couriers\CourierAccounts;
use App\Couriers\CourierRegistry;
use App\Http\Controllers\Controller;
use App\Models\CourierAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
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

        $target = Route::has('accounts.show') ? route('accounts.show', $account) : route('accounts.index');

        return redirect($target)->with('status', 'Connected');
    }
}
