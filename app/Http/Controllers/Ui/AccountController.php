<?php

namespace App\Http\Controllers\Ui;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ui\UpdatePasswordRequest;
use App\Http\Requests\Ui\UpdateProfileRequest;
use App\Models\Admin;
use App\Models\AuditEntry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\View\View;

/** The signed-in admin's own Profile (name, email) and Password. */
class AccountController extends Controller
{
    private const PASSWORD_ATTEMPTS = 5;

    private const PASSWORD_DECAY_SECONDS = 60;

    public function show(Request $request): View
    {
        return view('account.show', ['admin' => $request->user()]);
    }

    /** Profile card: name and email. A new email needs the current password; the name alone does not. */
    public function profile(UpdateProfileRequest $request): RedirectResponse
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

        return redirect()->route('account')->with('status', 'Saved');
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

        return redirect()->route('account')->with('status', 'Password changed');
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
