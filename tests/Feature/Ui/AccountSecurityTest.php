<?php

namespace Tests\Feature\Ui;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Real logins with real cookies, on the production session driver; nothing is shared between "browsers". */
class AccountSecurityTest extends TestCase
{
    use RefreshDatabase;

    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['session.driver' => 'database']);
        $this->admin = Admin::query()->create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'correct-horse-battery']);
    }

    /** @return array<string, string> the cookies one browser holds after signing in */
    private function signIn(bool $remember = false): array
    {
        $res = $this->fresh()->post('/login', ['login' => 'ana@example.com', 'password' => 'correct-horse-battery'] + ($remember ? ['remember' => '1'] : []))
            ->assertRedirect();

        return $this->cookiesFrom($res);
    }

    /** @return array<string, string> */
    private function cookiesFrom(TestResponse $res, array $jar = []): array
    {
        foreach ($res->headers->getCookies() as $c) {
            $jar[$c->getName()] = $res->getCookie($c->getName(), decrypt: true, unserialize: false)?->getValue() ?? '';
        }

        return $jar;
    }

    /** Forget the in-memory signed-in user and session so each request is judged by its cookies alone. */
    private function fresh(): static
    {
        Auth::forgetGuards();
        app('session')->forgetDrivers();
        $this->app->forgetInstance('session.store');
        app('cookie')->flushQueuedCookies();
        $this->flushHeaders();
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];

        return $this;
    }

    private function as(array $jar): static
    {
        return $this->fresh()->withCookies($jar);
    }

    private function changePassword(array $jar): TestResponse
    {
        return $this->as($jar)->put('/settings/account', [
            'name' => 'Ana', 'email' => 'ana@example.com', 'current_password' => 'correct-horse-battery',
            'password' => 'a-brand-new-passphrase', 'password_confirmation' => 'a-brand-new-passphrase',
        ]);
    }

    public function test_password_change_signs_out_other_sessions_and_old_remember_cookies(): void
    {
        $other = $this->signIn();
        $remembered = $this->signIn(remember: true);
        $recaller = Auth::guard('web')->getRecallerName();
        $rememberOnly = [$recaller => $remembered[$recaller]];
        $current = $this->signIn();

        $this->as($other)->get('/settings')->assertOk();
        $this->as($rememberOnly)->get('/settings')->assertOk();
        $tokenBefore = $this->admin->fresh()->remember_token;
        $currentId = $current[config('session.cookie')];

        $res = $this->changePassword($current)->assertRedirect('/settings?tab=account')->assertSessionHasNoErrors();
        $after = $this->cookiesFrom($res, $current);

        $this->assertNotSame($currentId, $after[config('session.cookie')], 'the current session id is regenerated');
        $this->assertNotSame($tokenBefore, $this->admin->fresh()->remember_token);

        $this->as($after)->get('/settings')->assertOk();
        $this->as($other)->get('/settings')->assertRedirect('/login');
        $this->as($rememberOnly)->get('/settings')->assertRedirect('/login');
        $this->as([config('session.cookie') => $currentId])->get('/settings')->assertRedirect('/login');
    }

    public function test_remembered_session_keeps_a_working_remember_cookie_after_its_own_change(): void
    {
        $jar = $this->signIn(remember: true);
        $recaller = Auth::guard('web')->getRecallerName();

        $after = $this->cookiesFrom($this->changePassword($jar)->assertSessionHasNoErrors(), $jar);

        $this->assertNotSame($jar[$recaller], $after[$recaller]);
        $this->as([$recaller => $after[$recaller]])->get('/settings')->assertOk();
    }

    public function test_current_password_check_is_throttled(): void
    {
        $this->actingAs($this->admin);
        $wrong = ['name' => 'Ana', 'email' => 'new@example.com', 'current_password' => 'nope'];
        $right = ['current_password' => 'correct-horse-battery'] + $wrong;

        for ($i = 0; $i < 5; $i++) {
            $this->put('/settings/account', $wrong)->assertSessionHasErrors(['current_password' => 'Current password is wrong.']);
        }
        $this->put('/settings/account', $right)->assertSessionHasErrors('current_password');
        $this->assertMatchesRegularExpression('/^Too many attempts\. Try again in \d+ seconds\.$/', session('errors')->first('current_password'));
        $this->assertSame('ana@example.com', $this->admin->fresh()->email);

        RateLimiter::clear('account|'.$this->admin->id.'|127.0.0.1');
        $this->put('/settings/account', $right)->assertSessionHas('status', 'Saved');
        $this->assertSame('new@example.com', $this->admin->fresh()->email);
    }
}
