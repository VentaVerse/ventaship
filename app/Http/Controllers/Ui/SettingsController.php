<?php

namespace App\Http\Controllers\Ui;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\AuditEntry;
use App\Rules\PhPhone;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
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

    /** Sender form field => settings key. */
    private const SENDER = [
        'pickup_contact_name' => 'pickup.contact_name',
        'pickup_contact_phone' => 'pickup.contact_phone',
        'pickup_address_1' => 'pickup.address_1',
        'pickup_address_2' => 'pickup.address_2',
        'pickup_barangay' => 'pickup.barangay',
        'pickup_city' => 'pickup.city',
        'pickup_state' => 'pickup.state',
        'pickup_postcode' => 'pickup.postcode',
    ];

    private const SENDER_REQUIRED = ['pickup_contact_name', 'pickup_contact_phone', 'pickup_address_1', 'pickup_city', 'pickup_state'];

    private const PASSWORD_ATTEMPTS = 5;

    private const PASSWORD_DECAY_SECONDS = 60;

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
            'publicAddress' => $settings->publicBaseUrl(),
            'sender' => collect(self::SENDER)->map(fn (string $key) => $settings->get($key))->all(),
            'admin' => $request->user(),
        ]);
    }

    public function update(Request $request, Settings $settings): RedirectResponse
    {
        $tab = $request->input('tab') === 'couriers' ? 'couriers' : 'general';
        $back = redirect()->route('settings.edit', ['tab' => $tab]);

        if ($tab === 'general') {
            $filled = collect(self::SENDER)->keys()->contains(fn (string $field) => trim((string) $request->input($field)) !== '');
            $max = ['string', 'max:255'];
            $senderRules = [
                'pickup_contact_name' => [$filled ? 'required' : 'nullable', ...$max],
                'pickup_contact_phone' => [$filled ? 'required' : 'nullable', 'string', 'max:32', new PhPhone('Not a valid PH mobile number.')],
                'pickup_address_1' => [$filled ? 'required' : 'nullable', ...$max],
                'pickup_address_2' => ['nullable', ...$max],
                'pickup_barangay' => ['nullable', ...$max],
                'pickup_city' => [$filled ? 'required' : 'nullable', ...$max],
                'pickup_state' => [$filled ? 'required' : 'nullable', ...$max],
                'pickup_postcode' => ['nullable', 'string', 'max:16'],
            ];
            $data = $request->validate([
                'shipments_reference_prefix' => ['required', 'regex:/^[A-Z0-9]{1,8}\z/'],
                'privacy_retention_days' => ['required', 'integer', 'between:30,3650'],
            ] + $senderRules, [
                'shipments_reference_prefix.*' => 'Use 1–8 capital letters or digits.',
                'privacy_retention_days.*' => 'Between 30 and 3650 days.',
                'pickup_*.required' => 'Required.',
                'pickup_*.max' => 'Too long.',
                'pickup_*.string' => 'Not valid.',
            ]);
            $new = [
                'shipments.reference_prefix' => $data['shipments_reference_prefix'],
                'privacy.retention_days' => (int) $data['privacy_retention_days'],
            ];
            foreach (self::SENDER as $field => $key) {
                $value = trim((string) ($data[$field] ?? ''));
                $new[$key] = $value === '' ? null : $value;
            }
        } else {
            // A switch is on only for an explicit "1"; an unchecked or absent field is a strict false.
            $data = $request->validate([
                'webhooks_public_base_url' => ['nullable', 'string', 'max:255', function (string $attribute, mixed $value, \Closure $fail) {
                    $parts = parse_url((string) $value);
                    $valid = is_array($parts) && ($parts['scheme'] ?? null) === 'https' && ($parts['host'] ?? '') !== ''
                        && array_intersect_key($parts, array_flip(['path', 'query', 'fragment', 'user', 'pass'])) === []
                        && ! str_ends_with((string) $value, '/') && ! str_contains((string) $value, '?') && ! str_contains((string) $value, '#');
                    if (! $valid) {
                        $fail('Use https://your-domain');
                    }
                }],
            ], ['webhooks_public_base_url.*' => 'Use https://your-domain']);
            $address = trim((string) ($data['webhooks_public_base_url'] ?? ''));
            $new = ['webhooks.public_base_url' => $address === '' ? null : $address];
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

        if (is_string($email = $request->input('email'))) {
            $request->merge(['email' => Str::lower(trim($email))]);
        }

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

        if (array_intersect(['email', 'password'], $changed) !== []) {
            $key = 'account|'.$admin->getKey().'|'.$request->ip();
            $refuse = fn (string $message) => back()
                ->withInput($request->except(['current_password', 'password', 'password_confirmation']))
                ->withErrors(['current_password' => $message]);

            if (RateLimiter::tooManyAttempts($key, self::PASSWORD_ATTEMPTS)) {
                return $refuse('Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.');
            }
            if (! Hash::check((string) ($data['current_password'] ?? ''), $admin->password)) {
                RateLimiter::hit($key, self::PASSWORD_DECAY_SECONDS);

                return $refuse('Current password is wrong.');
            }
            RateLimiter::clear($key);
        }

        if ($changed !== []) {
            DB::transaction(function () use ($admin, $data, $changed, $newPassword, $request) {
                $admin->name = $data['name'];
                $admin->email = $data['email'];
                if (in_array('password', $changed, true)) {
                    $admin->password = $newPassword; // hashed by the model cast
                    $admin->setRememberToken(Str::random(60));
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

        if (in_array('password', $changed, true)) {
            $this->signOutOtherSessions($request, $admin);
        }

        return redirect()->route('settings.edit', ['tab' => 'account'])->with('status', 'Saved');
    }

    /**
     * After a password change only this browser stays signed in: the remember token was rotated (old remember
     * cookies stop working), the `auth.session` middleware rejects sessions holding the old password hash, and on
     * the database driver the other session rows are deleted outright. This session moves to a new id.
     */
    private function signOutOtherSessions(Request $request, Admin $admin): void
    {
        $guard = Auth::guard('web');
        $remembered = $request->cookies->has($guard->getRecallerName());

        $guard->login($admin, $remembered); // re-queues a remember cookie carrying the new token
        $request->session()->regenerate(true);
        $request->session()->put('password_hash_web', $guard->hashPasswordForCookie($admin->getAuthPassword()));

        if (config('session.driver') === 'database') {
            DB::connection(config('session.connection'))->table(config('session.table', 'sessions'))
                ->where('user_id', $admin->getKey())
                ->where('id', '!=', $request->session()->getId())
                ->delete();
        }
    }
}
