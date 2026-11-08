<?php

namespace App\Http\Controllers\Ui;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\AuditEntry;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SettingsController extends Controller
{
    private const TABS = ['general' => 'General', 'couriers' => 'Couriers & safety', 'account' => 'Account'];

    /** Form field => settings key (dots are not valid in field names). */
    private const SWITCHES = [
        'safety_allow_live_lbc' => 'safety.allow_live_lbc',
        'safety_allow_live_spx' => 'safety.allow_live_spx',
    ];

    private const DEFAULTS = ['shipments.reference_prefix' => 'VS', 'privacy.retention_days' => 180];

    public function edit(Request $request, Settings $settings): View
    {
        $tab = (string) $request->query('tab');
        $tab = array_key_exists($tab, self::TABS) ? $tab : 'general';

        return view('settings.edit', [
            'tab' => $tab,
            'tabs' => self::TABS,
            'prefix' => $settings->get('shipments.reference_prefix', 'VS'),
            'retention' => $settings->get('privacy.retention_days', 180),
            'switches' => collect(self::SWITCHES)->map(fn (string $key) => $settings->get($key) === true)->all(),
            'admin' => $request->user(),
        ]);
    }

    public function update(Request $request, Settings $settings): RedirectResponse
    {
        $tab = $request->input('tab') === 'couriers' ? 'couriers' : 'general';
        $back = redirect()->route('settings.edit', ['tab' => $tab]);

        if ($tab === 'general') {
            $data = $request->validate([
                'shipments_reference_prefix' => ['required', 'regex:/^[A-Z0-9]{1,8}\z/'],
                'privacy_retention_days' => ['required', 'integer', 'between:30,3650'],
            ], [
                'shipments_reference_prefix.*' => 'Use 1–8 capital letters or digits.',
                'privacy_retention_days.*' => 'Between 30 and 3650 days.',
            ]);
            $new = [
                'shipments.reference_prefix' => $data['shipments_reference_prefix'],
                'privacy.retention_days' => (int) $data['privacy_retention_days'],
            ];
        } else {
            // A switch is on only for an explicit "1"; an unchecked or absent field is a strict false.
            $new = [];
            foreach (self::SWITCHES as $field => $key) {
                $new[$key] = $request->input($field) === '1';
            }
        }

        DB::transaction(function () use ($settings, $new, $request) {
            foreach ($new as $key => $value) {
                // Unset general keys read as their default; an unset switch counts as changed so false gets stored.
                $from = $settings->get($key, self::DEFAULTS[$key] ?? null);
                if ($from === $value) {
                    continue;
                }
                $settings->set($key, $value);
                AuditEntry::query()->create([
                    'actor_type' => 'admin',
                    'actor_id' => (string) $request->user()->getAuthIdentifier(),
                    'ip' => $request->ip(),
                    'action' => 'setting.changed',
                    'subject_type' => 'setting',
                    'subject_id' => $key,
                    'changes' => ['value' => ['from' => $from, 'to' => $value]],
                    'created_at' => now(),
                ]);
            }
        });

        return $back->with('status', 'Saved');
    }

    public function account(Request $request): RedirectResponse
    {
        /** @var Admin $admin */
        $admin = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('admins', 'email')->ignore($admin->getKey())],
            'current_password' => ['nullable', 'string'],
            'password' => ['nullable', 'string', 'min:12', 'confirmed'],
        ], [
            'name.required' => 'Required.',
            'name.max' => 'Too long.',
            'email.required' => 'Required.',
            'email.email' => 'Not a valid email.',
            'email.max' => 'Too long.',
            'email.unique' => 'Email already in use.',
            'password.min' => 'At least 12 characters.',
            'password.confirmed' => "Passwords don't match.",
        ]);

        $changed = [];
        foreach (['name', 'email'] as $field) {
            if ($data[$field] !== $admin->{$field}) {
                $changed[] = $field;
            }
        }
        $newPassword = $data['password'] ?? null;
        if ($newPassword !== null && $newPassword !== '') {
            $changed[] = 'password';
        }

        if (array_intersect(['email', 'password'], $changed) !== []
            && ! Hash::check((string) ($data['current_password'] ?? ''), $admin->password)) {
            return back()->withInput($request->except(['current_password', 'password', 'password_confirmation']))
                ->withErrors(['current_password' => 'Current password is wrong.']);
        }

        if ($changed !== []) {
            DB::transaction(function () use ($admin, $data, $changed, $newPassword, $request) {
                $admin->name = $data['name'];
                $admin->email = $data['email'];
                if (in_array('password', $changed, true)) {
                    $admin->password = $newPassword; // hashed by the model cast
                }
                $admin->save();

                AuditEntry::query()->create([
                    'actor_type' => 'admin',
                    'actor_id' => (string) $admin->getKey(),
                    'ip' => $request->ip(),
                    'action' => 'admin.updated',
                    'subject_type' => 'admin',
                    'subject_id' => (string) $admin->getKey(),
                    'changes' => ['fields' => $changed],
                    'created_at' => now(),
                ]);
            });
        }

        return redirect()->route('settings.edit', ['tab' => 'account'])->with('status', 'Saved');
    }
}
