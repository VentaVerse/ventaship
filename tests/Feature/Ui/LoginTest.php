<?php

namespace Tests\Feature\Ui;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Admin
    {
        return Admin::query()->create(['name' => 'Ana', 'email' => 'ana@example.com', 'username' => 'ana', 'password' => 'correct-horse-battery']);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
    }

    public function test_guest_post_to_app_route_is_redirected(): void
    {
        $hit = false;
        Route::middleware(['web', 'auth'])->post('/_probe', function () use (&$hit) {
            $hit = true;
        });

        $this->post('/_probe')->assertRedirect('/login');
        $this->assertFalse($hit);
    }

    public function test_login_page_renders_and_signed_in_admin_is_sent_away(): void
    {
        $this->get('/login')->assertOk()->assertSee('Sign in')->assertDontSee('Wrong username or password.');
        $this->actingAs($this->admin())->get('/login')->assertRedirect('/');
    }

    public function test_admin_signs_in(): void
    {
        $admin = $this->admin();
        $this->get('/login');
        $before = session()->getId();

        $this->post('/login', ['login' => 'ANA@example.com', 'password' => 'correct-horse-battery'])->assertRedirect('/');

        $this->assertSame($admin->id, auth()->id());
        $this->assertNotNull($admin->fresh()->last_login_at);
        $this->assertNotSame($before, session()->getId());
        $this->get('/')->assertOk();
    }

    public function test_wrong_password_shows_one_message(): void
    {
        $this->admin();

        $this->from('/login')->post('/login', ['login' => 'ana@example.com', 'password' => 'nope'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['auth' => 'Wrong username or password.'])
            ->assertSessionHasInput('login', 'ana@example.com')
            ->assertSessionMissing('_old_input.password');
        $this->assertGuest();

        $this->post('/login', ['login' => 'nobody@example.com', 'password' => 'nope'])
            ->assertSessionHasErrors(['auth' => 'Wrong username or password.']);
    }

    public function test_empty_fields_say_required(): void
    {
        $this->post('/login', ['login' => '', 'password' => ''])
            ->assertSessionHasErrors(['login' => 'Required.', 'password' => 'Required.']);
    }

    public function test_error_state_renders_alert(): void
    {
        $this->admin();
        $this->from('/login')->post('/login', ['login' => 'ana@example.com', 'password' => 'nope']);

        $this->followingRedirects()->get('/login')->assertSee('Wrong username or password.')->assertSee('ana@example.com');
    }

    public function test_remember_box_is_checked_by_default_and_keeps_an_unchecked_choice(): void
    {
        $this->admin();
        $this->get('/login')->assertSee('name="remember" value="1" checked', false);

        $this->from('/login')->post('/login', ['login' => 'ana@example.com', 'password' => 'nope']);
        $html = $this->followingRedirects()->get('/login')->getContent();
        $this->assertStringNotContainsString('name="remember" value="1" checked', $html);
        $this->assertStringContainsString('name="remember"', $html);
    }

    public function test_login_is_throttled(): void
    {
        $this->admin();
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['login' => 'ana@example.com', 'password' => 'nope'])
                ->assertSessionHasErrors(['auth' => 'Wrong username or password.']);
        }

        $response = $this->post('/login', ['login' => 'Ana@example.com', 'password' => 'correct-horse-battery']);
        $response->assertSessionHasErrors('auth');
        $this->assertMatchesRegularExpression('/^Too many attempts\. Try again in \d+ seconds\.$/', session('errors')->first('auth'));
        $this->assertGuest();
    }

    public function test_remember_me_sets_cookie(): void
    {
        $this->admin();

        $this->post('/login', ['login' => 'ana@example.com', 'password' => 'correct-horse-battery', 'remember' => '1'])
            ->assertCookie(auth()->guard()->getRecallerName());
    }

    public function test_admin_signs_in_with_username(): void
    {
        $admin = $this->admin();

        $this->post('/login', ['login' => ' Ana ', 'password' => 'correct-horse-battery'])->assertRedirect('/');

        $this->assertAuthenticatedAs($admin);
    }

    public function test_wrong_password_for_username_shows_one_message(): void
    {
        $this->admin();

        $this->post('/login', ['login' => 'ana', 'password' => 'nope'])
            ->assertSessionHasErrors(['auth' => 'Wrong username or password.']);
        $this->assertGuest();
    }

    public function test_login_page_asks_for_username(): void
    {
        $this->get('/login')->assertOk()->assertSee('Username')->assertSee('name="login"', false);
    }

    public function test_empty_fields_are_marked_under_each_field_not_in_the_alert(): void
    {
        $this->from('/login')->post('/login', ['login' => 'ana', 'password' => '']);
        $html = $this->get('/login')->getContent();

        $this->assertStringContainsString('id="f-password-error"', $html);
        $this->assertStringNotContainsString('id="f-login-error"', $html);
        $this->assertMatchesRegularExpression('/id="f-password"[^>]*aria-invalid="true"/', $html);
        $this->assertMatchesRegularExpression('/id="f-password"[^>]*aria-describedby="f-password-error"/', $html);
        $this->assertStringNotContainsString('role="alert"', $html);
    }

    public function test_wrong_password_shows_the_alert_without_marking_a_field(): void
    {
        $this->admin();
        $this->from('/login')->post('/login', ['login' => 'ana@example.com', 'password' => 'nope']);
        $html = $this->get('/login')->getContent();

        $this->assertStringContainsString('role="alert"', $html);
        $this->assertStringNotContainsString('aria-invalid', $html);
    }

    public function test_login_label_and_password_reveal(): void
    {
        $this->get('/login')->assertOk()
            ->assertSee('Username or email')
            ->assertSee('class="lg-eye"', false)
            ->assertSee("show ? 'Hide password' : 'Show password'", false);
    }

    public function test_logout_invalidates_session(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->get('/');
        $before = session()->getId();

        $this->post('/logout')->assertRedirect('/login');

        $this->assertGuest();
        $this->assertNotSame($before, session()->getId());
        $this->get('/')->assertRedirect('/login');
        $this->assertTrue(Hash::check('correct-horse-battery', $admin->fresh()->password));
    }
}
