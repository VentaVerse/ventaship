<?php

namespace Tests\Feature\Ui;

use App\Models\Admin;
use App\Models\AuditEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** The Account page: the admin's own Profile (name, email) and Password cards. Moved here from Settings. */
class AccountPageTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Admin
    {
        return Admin::query()->create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'correct-horse-battery']);
    }

    private function xpath(string $html): \DOMXPath
    {
        $doc = new \DOMDocument;
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?>'.$html);
        libxml_clear_errors();

        return new \DOMXPath($doc);
    }

    public function test_account_page_renders_with_current_values(): void
    {
        $html = $this->actingAs($this->admin())->get('/account')->assertOk()
            ->assertSee('value="ana@example.com"', false)->assertSee('value="Ana"', false)
            ->getContent();
        $this->assertSame('/account', parse_url(route('account'), PHP_URL_PATH));
        $x = $this->xpath($html);
        $this->assertSame('Account', trim($x->query('//h1')->item(0)?->textContent ?? ''));
        $this->assertStringStartsWith('Account · ', trim($x->query('//title')->item(0)?->textContent ?? ''));
        $this->assertSame(0, $x->query('//nav[contains(@class, "x-tabs")]')->length, 'no tabs');
    }

    public function test_account_page_needs_sign_in(): void
    {
        $this->get('/account')->assertRedirect('/login');
        $this->put('/account', ['name' => 'X', 'email' => 'x@example.com'])->assertRedirect('/login');
        $this->put('/account/password', [])->assertRedirect('/login');
    }

    public function test_email_change_requires_current_password(): void
    {
        $admin = $this->admin();
        $this->admin_other();

        $this->actingAs($admin)->put('/account', ['name' => 'Ana', 'email' => 'new@example.com'])
            ->assertSessionHasErrors(['current_password' => 'Current password is wrong.']);
        $this->actingAs($admin)->put('/account', ['name' => 'Ana', 'email' => 'new@example.com', 'current_password' => 'nope'])
            ->assertSessionHasErrors(['current_password' => 'Current password is wrong.']);
        $this->assertSame('ana@example.com', $admin->fresh()->email);

        $this->actingAs($admin)->put('/account', ['name' => 'Ana', 'email' => 'bob@example.com', 'current_password' => 'correct-horse-battery'])
            ->assertSessionHasErrors('email');

        $this->actingAs($admin)->put('/account', ['name' => 'Ana B', 'email' => 'new@example.com', 'current_password' => 'correct-horse-battery'])
            ->assertSessionHasNoErrors();
        $this->assertSame('new@example.com', $admin->fresh()->email);
        $this->assertSame('Ana B', $admin->fresh()->name);
    }

    public function test_email_is_trimmed_and_lowercased_before_checks_and_save(): void
    {
        $admin = $this->admin();
        $this->admin_other();

        $this->actingAs($admin)->put('/account', ['name' => 'Ana', 'email' => '  BOB@Example.com '])
            ->assertSessionHasErrors(['email' => 'Email already in use.']);

        $this->actingAs($admin)->put('/account', ['name' => 'Ana', 'email' => ' ANA@Example.COM '])
            ->assertSessionHas('status', 'Saved');
        $this->assertSame(0, AuditEntry::query()->where('action', 'admin.updated')->count(), 'same email in other case is no change');

        $this->actingAs($admin)->put('/account', ['name' => 'Ana', 'email' => ' New@Example.com', 'current_password' => 'correct-horse-battery'])
            ->assertSessionHas('status', 'Saved');
        $this->assertSame('new@example.com', $admin->fresh()->email);
    }

    private function admin_other(): void
    {
        Admin::query()->create(['name' => 'Bob', 'email' => 'bob@example.com', 'password' => 'another-long-password']);
    }

    public function test_name_change_alone_needs_no_password(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->put('/account', ['name' => 'Anna', 'email' => 'ana@example.com'])->assertSessionHasNoErrors();
        $this->assertSame('Anna', $admin->fresh()->name);
    }

    public function test_password_change_rehashes_and_keeps_session(): void
    {
        $admin = $this->admin();
        $old = $admin->password;

        $this->post('/login', ['login' => 'ana@example.com', 'password' => 'correct-horse-battery'])->assertRedirect();
        $this->assertAuthenticatedAs($admin);

        $this->put('/account/password', [
            'current_password' => 'correct-horse-battery',
            'password' => 'a-brand-new-passphrase', 'password_confirmation' => 'a-brand-new-passphrase',
        ])->assertRedirect('/account')->assertSessionHasNoErrors()->assertSessionHas('status', 'Password changed');

        $fresh = $admin->fresh();
        $this->assertNotSame($old, $fresh->password);
        $this->assertTrue(Hash::check('a-brand-new-passphrase', $fresh->password));
        $this->get('/settings')->assertOk();
        $this->assertAuthenticatedAs($fresh);
    }

    public function test_account_error_messages_are_short_facts(): void
    {
        $admin = $this->admin();
        $this->admin_other();

        $this->actingAs($admin)->put('/account', ['name' => '', 'email' => ''])
            ->assertSessionHasErrors(['name' => 'Required.', 'email' => 'Required.']);
        $this->actingAs($admin)->put('/account', ['name' => 'Ana', 'email' => 'nope'])
            ->assertSessionHasErrors(['email' => 'Not a valid email.']);
        $this->actingAs($admin)->put('/account', ['name' => 'Ana', 'email' => 'bob@example.com', 'current_password' => 'correct-horse-battery'])
            ->assertSessionHasErrors(['email' => 'Email already in use.']);
    }

    public function test_password_rules(): void
    {
        $admin = $this->admin();
        $base = ['current_password' => 'correct-horse-battery'];

        $this->actingAs($admin)->put('/account/password', $base + ['password' => 'short', 'password_confirmation' => 'short'])
            ->assertSessionHasErrors(['password' => 'At least 12 characters.'], null, 'password');
        $this->actingAs($admin)->put('/account/password', $base + ['password' => 'a-brand-new-passphrase', 'password_confirmation' => 'different-passphrase'])
            ->assertSessionHasErrors(['password' => "Passwords don't match."], null, 'password');
        $this->actingAs($admin)->put('/account/password', ['password' => 'a-brand-new-passphrase', 'password_confirmation' => 'a-brand-new-passphrase'])
            ->assertSessionHasErrors(['current_password' => 'Required.'], null, 'password');
        $this->assertTrue(Hash::check('correct-horse-battery', $admin->fresh()->password));
    }

    public function test_profile_save_ignores_password_fields(): void
    {
        $admin = $this->admin();
        $old = $admin->password;

        $this->actingAs($admin)->put('/account', [
            'name' => 'Anna', 'email' => 'ana@example.com', 'current_password' => 'correct-horse-battery',
            'password' => 'a-brand-new-passphrase', 'password_confirmation' => 'a-brand-new-passphrase',
        ])->assertRedirect('/account')->assertSessionHasNoErrors()->assertSessionHas('status', 'Saved');

        $this->assertSame('Anna', $admin->fresh()->name);
        $this->assertSame($old, $admin->fresh()->password);
        $this->assertSame(['name'], AuditEntry::query()->where('action', 'admin.updated')->firstOrFail()->changes['fields']);
    }

    public function test_wrong_current_password_lands_under_the_password_cards_field(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->from('/account')->put('/account/password', [
            'current_password' => 'nope', 'password' => 'a-brand-new-passphrase', 'password_confirmation' => 'a-brand-new-passphrase',
        ])->assertRedirect('/account')
            ->assertSessionHasErrors(['current_password' => 'Current password is wrong.'], null, 'password')
            ->assertSessionDoesntHaveErrors('current_password');
        $this->assertTrue(Hash::check('correct-horse-battery', $admin->fresh()->password));

        $old = session()->getOldInput();
        foreach (['current_password', 'password', 'password_confirmation'] as $secret) {
            $this->assertArrayNotHasKey($secret, $old);
        }

        $html = $this->actingAs($admin)->get('/account')->getContent();
        $x = $this->xpath($html);
        $this->assertSame('Current password is wrong.', trim($x->query('//*[@id="f-current-password-error"]')->item(0)?->textContent ?? ''));
        $this->assertSame('f-current-password-error', $x->query('//input[@id="f-current-password"]')->item(0)?->getAttribute('aria-describedby'));
        $this->assertNull($x->query('//*[@id="f-profile-current-password-error"]')->item(0), 'not under the Profile card');
        $this->assertSame(0, $x->query('//*[contains(@class, "x-flash__line--bad")]')->length, 'not repeated in the flash');
    }

    public function test_email_change_error_lands_under_the_profile_cards_current_password(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->from('/account')
            ->put('/account', ['name' => 'Ana', 'email' => 'new@example.com', 'current_password' => 'nope'])
            ->assertSessionHasErrors(['current_password' => 'Current password is wrong.']);
        $this->assertArrayNotHasKey('current_password', session()->getOldInput());

        $html = $this->actingAs($admin)->get('/account')->getContent();
        $x = $this->xpath($html);
        $this->assertSame('new@example.com', $x->query('//input[@id="f-email"]')->item(0)?->getAttribute('value'));
        $this->assertSame('Current password is wrong.', trim($x->query('//*[@id="f-profile-current-password-error"]')->item(0)?->textContent ?? ''));
        $this->assertNull($x->query('//*[@id="f-current-password-error"]')->item(0), 'not under the Password card');
        $this->assertSame(0, $x->query('//*[contains(@class, "x-flash__line--bad")]')->length);
    }

    public function test_account_page_has_separate_profile_and_password_cards(): void
    {
        $html = $this->actingAs($this->admin())->get('/account')->assertOk()->getContent();
        $x = $this->xpath($html);

        $profile = $x->query('//form[@action="'.route('account.profile').'"]')->item(0);
        $password = $x->query('//form[@action="'.route('account.password').'"]')->item(0);
        $this->assertNotNull($profile);
        $this->assertNotNull($password);

        $names = fn (\DOMNode $form) => array_values(array_filter(array_map(
            fn (\DOMElement $el) => $el->getAttribute('name'),
            iterator_to_array($x->query('.//input[@name and not(@type="hidden")]', $form)),
        )));
        $this->assertSame(['name', 'email', 'current_password'], $names($profile));
        $this->assertSame(['current_password', 'password', 'password_confirmation'], $names($password));
        $this->assertSame(['Save'], array_map(fn ($b) => trim($b->textContent), iterator_to_array($x->query('.//button[@type="submit"]', $profile))));
        $this->assertSame(['Change password'], array_map(fn ($b) => trim($b->textContent), iterator_to_array($x->query('.//button[@type="submit"]', $password))));

        // The profile's Current password only shows (and is only sent) while the email differs.
        $row = $x->query('//input[@id="f-profile-current-password"]/ancestor::div[contains(@class, "st-row")]')->item(0);
        $this->assertSame('emailChanged', $row?->getAttribute('x-show'));
        $this->assertSame('! emailChanged', $x->query('//input[@id="f-profile-current-password"]')->item(0)->getAttribute('x-bind:disabled'));

        // Every password input has the show/hide eye.
        $this->assertSame(4, $x->query('//input[@type="password"]/following-sibling::button[contains(@class, "x-pass__eye")]')->length);
        $this->assertStringNotContainsString('Discard', $html);
        $this->assertStringNotContainsString('style="', $html);
        $this->assertStringNotContainsString('<style', $html);
    }

    public function test_audit_never_contains_password(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->put('/account', [
            'name' => 'Ana', 'email' => 'x@example.com', 'current_password' => 'correct-horse-battery',
        ])->assertSessionHasNoErrors();
        $this->actingAs($admin->fresh())->put('/account/password', [
            'current_password' => 'correct-horse-battery',
            'password' => 'a-brand-new-passphrase', 'password_confirmation' => 'a-brand-new-passphrase',
        ])->assertSessionHasNoErrors();

        $rows = AuditEntry::query()->where('action', 'admin.updated')->orderBy('id')->get();
        $this->assertSame([['email'], ['password']], $rows->map(fn ($r) => $r->changes['fields'])->all());
        $this->assertSame('admin', $rows->first()->actor_type);
        $dump = json_encode($rows->map->getAttributes()->all());
        $this->assertStringNotContainsString('a-brand-new-passphrase', $dump);
        $this->assertStringNotContainsString('correct-horse-battery', $dump);
        $this->assertStringNotContainsString($admin->fresh()->password, $dump);
    }
}
