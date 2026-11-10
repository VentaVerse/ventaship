<?php

namespace App\Http\Controllers\Ui;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ui\SaveGeneralSettingsRequest;
use App\Http\Requests\Ui\UpdatePasswordRequest;
use App\Http\Requests\Ui\UpdateProfileRequest;
use App\Models\Admin;
use App\Models\AuditEntry;
use App\Support\SchedulerCron;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\View\View;

class SettingsController extends Controller
{
    private const TABS = ['general' => 'General', 'jobs' => 'Scheduled jobs', 'account' => 'Account'];

    private const DEFAULTS = ['shipments.reference_prefix' => 'VS'];

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
            'admin' => $request->user(),
            'cron' => $tab === 'jobs' ? $this->cron($settings) : null,
        ]);
    }

    /** @return array{line: string, lastRun: array{0: string, 1: string}} */
    private function cron(Settings $settings): array
    {
        return [
            'line' => SchedulerCron::line(),
            'lastRun' => SchedulerCron::lastRunBadge(SchedulerCron::lastRun($settings)),
        ];
    }

    public function update(SaveGeneralSettingsRequest $request, Settings $settings): RedirectResponse
    {
        $data = $request->validated();
        $new = [
            'shipments.reference_prefix' => $data['shipments_reference_prefix'],
        ];

        DB::transaction(function () use ($settings, $new, $request) {
            foreach ($new as $key => $value) {
                // Unset keys read as their default.
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

        return redirect()->route('settings.edit', ['tab' => 'general'])->with('status', 'Saved');
    }

    /** Profile card: name and email. A new email needs the current password; the name alone does not. */
    public function account(UpdateProfileRequest $request): RedirectResponse
    {
        /** @var Admin $admin */
        $admin = $request->user();
        $data = $request->validated();

        $changed = array_values(array_filter(['name', 'email'], fn (string $field) => $data[$field] !== $admin->{$field}));

        if (in_array('email', $changed, true)) {
            $refusal = $this->checkCurrentPassword($request, $admin, (string) ($data['current_password'] ?? ''));
            if ($refusal !== null) {
                return back()
                    ->withInput($request->only(['name', 'email']))
                    ->withErrors(['current_password' => $refusal]);
            }
        }

        if ($changed !== []) {
            DB::transaction(function () use ($admin, $data, $changed, $request) {
                $admin->name = $data['name'];
                $admin->email = $data['email'];
                $admin->save();
                $this->audit($request, $admin, $changed);
            });
        }

        return redirect()->route('settings.edit', ['tab' => 'account'])->with('status', 'Saved');
    }

    /** Password card. Only this browser stays signed in afterwards. */
    public function password(UpdatePasswordRequest $request): RedirectResponse
    {
        /** @var Admin $admin */
        $admin = $request->user();
        $data = $request->validated();

        $refusal = $this->checkCurrentPassword($request, $admin, (string) $data['current_password']);
        if ($refusal !== null) {
            return back()->withErrors(['current_password' => $refusal], UpdatePasswordRequest::BAG);
        }

        DB::transaction(function () use ($admin, $data, $request) {
            $admin->password = $data['password']; // hashed by the model cast
            $admin->setRememberToken(Str::random(60));
            $admin->save();
            $this->audit($request, $admin, ['password']);
        });

        $this->signOutOtherSessions($request, $admin);

        return redirect()->route('settings.edit', ['tab' => 'account'])->with('status', 'Password changed');
    }

    /**
     * The current password, throttled per admin and IP (one budget for both cards). Null when it is right, else
     * the message for its field.
     */
    private function checkCurrentPassword(Request $request, Admin $admin, string $given): ?string
    {
        $key = 'account|'.$admin->getKey().'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, self::PASSWORD_ATTEMPTS)) {
            return 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.';
        }
        if (! Hash::check($given, $admin->password)) {
            RateLimiter::hit($key, self::PASSWORD_DECAY_SECONDS);

            return 'Current password is wrong.';
        }
        RateLimiter::clear($key);

        return null;
    }

    /** @param  list<string>  $fields  names only, never values */
    private function audit(Request $request, Admin $admin, array $fields): void
    {
        AuditEntry::query()->create([
            'actor_type' => 'admin',
            'actor_id' => (string) $admin->getKey(),
            'ip' => $request->ip(),
            'action' => 'admin.updated',
            'subject_type' => 'admin',
            'subject_id' => (string) $admin->getKey(),
            'changes' => ['fields' => $fields],
            'created_at' => now(),
        ]);
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
