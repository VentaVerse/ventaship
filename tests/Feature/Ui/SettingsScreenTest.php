<?php

namespace Tests\Feature\Ui;

use App\Console\Commands\ScheduleHeartbeat;
use App\Models\Admin;
use App\Models\AuditEntry;
use App\Models\Setting;
use App\Support\SchedulerCron;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Js;
use Tests\TestCase;

class SettingsScreenTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Admin
    {
        return Admin::query()->create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'correct-horse-battery']);
    }

    public function test_tabs_render_with_current_values(): void
    {
        $admin = $this->admin();
        app(Settings::class)->set('shipments.reference_prefix', 'ZZ');

        $this->actingAs($admin)->get('/settings')
            ->assertOk()->assertSee('value="ZZ"', false)
            ->assertDontSee('privacy_retention_days', false)->assertDontSee('Keep recipient details');

        $this->actingAs($admin)->get('/settings?tab=bogus')->assertOk()->assertSee('value="ZZ"', false);

        $this->actingAs($admin)->get('/settings?tab=couriers')->assertOk()
            ->assertSee('value="ZZ"', false)
            ->assertDontSee('Couriers &amp; safety', false)->assertDontSee('Allow live')->assertDontSee('Public address');

        $this->actingAs($admin)->get('/settings?tab=account')->assertOk()
            ->assertSee('value="ana@example.com"', false);
    }

    public function test_invalid_prefix_rejected_with_message(): void
    {
        foreach (['ab-1', '', 'ABCDEFGHI', 'ñ1'] as $bad) {
            $this->actingAs($this->admin ??= $this->admin())->from('/settings')
                ->put('/settings', ['tab' => 'general', 'shipments_reference_prefix' => $bad])
                ->assertRedirect('/settings')
                ->assertSessionHasErrors(['shipments_reference_prefix' => 'Use 1–8 letters or digits.']);
        }
        $this->actingAs($this->admin)->from('/settings')
            ->put('/settings', ['tab' => 'general', 'shipments_reference_prefix' => ['VS']])
            ->assertSessionHasErrors('shipments_reference_prefix');

        $this->assertNull(app(Settings::class)->get('shipments.reference_prefix'));
    }

    private ?Admin $admin = null;

    public function test_lowercase_prefix_is_saved_in_capitals(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->put('/settings', ['tab' => 'general', 'shipments_reference_prefix' => ' ab1 '])
            ->assertRedirect('/settings?tab=general')->assertSessionHasNoErrors();
        $this->assertSame('AB1', app(Settings::class)->get('shipments.reference_prefix'));

        $html = $this->actingAs($admin)->get('/settings')->assertOk()->assertSee('value="AB1"', false)->getContent();
        $this->assertMatchesRegularExpression('/<input[^>]*id="f-shipments-reference-prefix"[^>]*>/', $html);
        preg_match('/<input[^>]*id="f-shipments-reference-prefix"[^>]*>/', $html, $m);
        $this->assertStringContainsString('autocapitalize="characters"', $m[0]);
        $this->assertStringContainsString('st-caps', $m[0]);
        $this->assertMatchesRegularExpression('/\.st-caps\s*\{[^}]*text-transform:\s*uppercase;/s', (string) file_get_contents(resource_path('css/pages/settings.css')));
    }

    public function test_only_changed_keys_audited_with_admin_actor(): void
    {
        $admin = $this->admin();
        app(Settings::class)->set('shipments.reference_prefix', 'AB');

        $this->actingAs($admin)->put('/settings', ['tab' => 'general', 'shipments_reference_prefix' => 'CD']);

        $rows = AuditEntry::query()->where('action', 'setting.changed')->get();
        $this->assertCount(1, $rows);
        $row = $rows->first();
        $this->assertSame('shipments.reference_prefix', $row->subject_id);
        $this->assertSame('admin', $row->actor_type);
        $this->assertSame((string) $admin->id, $row->actor_id);
        $this->assertSame(['value' => ['from' => 'AB', 'to' => 'CD']], $row->changes);
    }

    public function test_email_change_requires_current_password(): void
    {
        $admin = $this->admin();
        $this->admin_other();

        $this->actingAs($admin)->put('/settings/account', ['name' => 'Ana', 'email' => 'new@example.com'])
            ->assertSessionHasErrors(['current_password' => 'Current password is wrong.']);
        $this->actingAs($admin)->put('/settings/account', ['name' => 'Ana', 'email' => 'new@example.com', 'current_password' => 'nope'])
            ->assertSessionHasErrors(['current_password' => 'Current password is wrong.']);
        $this->assertSame('ana@example.com', $admin->fresh()->email);

        $this->actingAs($admin)->put('/settings/account', ['name' => 'Ana', 'email' => 'bob@example.com', 'current_password' => 'correct-horse-battery'])
            ->assertSessionHasErrors('email');

        $this->actingAs($admin)->put('/settings/account', ['name' => 'Ana B', 'email' => 'new@example.com', 'current_password' => 'correct-horse-battery'])
            ->assertSessionHasNoErrors();
        $this->assertSame('new@example.com', $admin->fresh()->email);
        $this->assertSame('Ana B', $admin->fresh()->name);
    }

    public function test_email_is_trimmed_and_lowercased_before_checks_and_save(): void
    {
        $admin = $this->admin();
        $this->admin_other();

        $this->actingAs($admin)->put('/settings/account', ['name' => 'Ana', 'email' => '  BOB@Example.com '])
            ->assertSessionHasErrors(['email' => 'Email already in use.']);

        $this->actingAs($admin)->put('/settings/account', ['name' => 'Ana', 'email' => ' ANA@Example.COM '])
            ->assertSessionHas('status', 'Saved');
        $this->assertSame(0, AuditEntry::query()->where('action', 'admin.updated')->count(), 'same email in other case is no change');

        $this->actingAs($admin)->put('/settings/account', ['name' => 'Ana', 'email' => ' New@Example.com', 'current_password' => 'correct-horse-battery'])
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

        $this->actingAs($admin)->put('/settings/account', ['name' => 'Anna', 'email' => 'ana@example.com'])->assertSessionHasNoErrors();
        $this->assertSame('Anna', $admin->fresh()->name);
    }

    public function test_password_change_rehashes_and_keeps_session(): void
    {
        $admin = $this->admin();
        $old = $admin->password;

        $this->post('/login', ['login' => 'ana@example.com', 'password' => 'correct-horse-battery'])->assertRedirect();
        $this->assertAuthenticatedAs($admin);

        $this->put('/settings/password', [
            'current_password' => 'correct-horse-battery',
            'password' => 'a-brand-new-passphrase', 'password_confirmation' => 'a-brand-new-passphrase',
        ])->assertRedirect('/settings?tab=account')->assertSessionHasNoErrors()->assertSessionHas('status', 'Password changed');

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

        $this->actingAs($admin)->put('/settings/account', ['name' => '', 'email' => ''])
            ->assertSessionHasErrors(['name' => 'Required.', 'email' => 'Required.']);
        $this->actingAs($admin)->put('/settings/account', ['name' => 'Ana', 'email' => 'nope'])
            ->assertSessionHasErrors(['email' => 'Not a valid email.']);
        $this->actingAs($admin)->put('/settings/account', ['name' => 'Ana', 'email' => 'bob@example.com', 'current_password' => 'correct-horse-battery'])
            ->assertSessionHasErrors(['email' => 'Email already in use.']);
    }

    public function test_password_rules(): void
    {
        $admin = $this->admin();
        $base = ['current_password' => 'correct-horse-battery'];

        $this->actingAs($admin)->put('/settings/password', $base + ['password' => 'short', 'password_confirmation' => 'short'])
            ->assertSessionHasErrors(['password' => 'At least 12 characters.'], null, 'password');
        $this->actingAs($admin)->put('/settings/password', $base + ['password' => 'a-brand-new-passphrase', 'password_confirmation' => 'different-passphrase'])
            ->assertSessionHasErrors(['password' => "Passwords don't match."], null, 'password');
        $this->actingAs($admin)->put('/settings/password', ['password' => 'a-brand-new-passphrase', 'password_confirmation' => 'a-brand-new-passphrase'])
            ->assertSessionHasErrors(['current_password' => 'Required.'], null, 'password');
        $this->assertTrue(Hash::check('correct-horse-battery', $admin->fresh()->password));
    }

    public function test_profile_save_ignores_password_fields(): void
    {
        $admin = $this->admin();
        $old = $admin->password;

        $this->actingAs($admin)->put('/settings/account', [
            'name' => 'Anna', 'email' => 'ana@example.com', 'current_password' => 'correct-horse-battery',
            'password' => 'a-brand-new-passphrase', 'password_confirmation' => 'a-brand-new-passphrase',
        ])->assertRedirect('/settings?tab=account')->assertSessionHasNoErrors()->assertSessionHas('status', 'Saved');

        $this->assertSame('Anna', $admin->fresh()->name);
        $this->assertSame($old, $admin->fresh()->password);
        $this->assertSame(['name'], AuditEntry::query()->where('action', 'admin.updated')->firstOrFail()->changes['fields']);
    }

    public function test_wrong_current_password_lands_under_the_password_cards_field(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->from('/settings?tab=account')->put('/settings/password', [
            'current_password' => 'nope', 'password' => 'a-brand-new-passphrase', 'password_confirmation' => 'a-brand-new-passphrase',
        ])->assertRedirect('/settings?tab=account')
            ->assertSessionHasErrors(['current_password' => 'Current password is wrong.'], null, 'password')
            ->assertSessionDoesntHaveErrors('current_password');
        $this->assertTrue(Hash::check('correct-horse-battery', $admin->fresh()->password));

        $old = session()->getOldInput();
        foreach (['current_password', 'password', 'password_confirmation'] as $secret) {
            $this->assertArrayNotHasKey($secret, $old);
        }

        $html = $this->actingAs($admin)->get('/settings?tab=account')->getContent();
        $x = $this->xpath($html);
        $this->assertSame('Current password is wrong.', trim($x->query('//*[@id="f-current-password-error"]')->item(0)?->textContent ?? ''));
        $this->assertSame('f-current-password-error', $x->query('//input[@id="f-current-password"]')->item(0)?->getAttribute('aria-describedby'));
        $this->assertNull($x->query('//*[@id="f-profile-current-password-error"]')->item(0), 'not under the Profile card');
        $this->assertSame(0, $x->query('//*[contains(@class, "x-flash__line--bad")]')->length, 'not repeated in the flash');
    }

    public function test_email_change_error_lands_under_the_profile_cards_current_password(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->from('/settings?tab=account')
            ->put('/settings/account', ['name' => 'Ana', 'email' => 'new@example.com', 'current_password' => 'nope'])
            ->assertSessionHasErrors(['current_password' => 'Current password is wrong.']);
        $this->assertArrayNotHasKey('current_password', session()->getOldInput());

        $html = $this->actingAs($admin)->get('/settings?tab=account')->getContent();
        $x = $this->xpath($html);
        $this->assertSame('new@example.com', $x->query('//input[@id="f-email"]')->item(0)?->getAttribute('value'));
        $this->assertSame('Current password is wrong.', trim($x->query('//*[@id="f-profile-current-password-error"]')->item(0)?->textContent ?? ''));
        $this->assertNull($x->query('//*[@id="f-current-password-error"]')->item(0), 'not under the Password card');
        $this->assertSame(0, $x->query('//*[contains(@class, "x-flash__line--bad")]')->length);
    }

    public function test_account_tab_has_separate_profile_and_password_cards(): void
    {
        $html = $this->actingAs($this->admin())->get('/settings?tab=account')->assertOk()->getContent();
        $x = $this->xpath($html);

        $profile = $x->query('//form[@action="'.route('settings.account').'"]')->item(0);
        $password = $x->query('//form[@action="'.route('settings.password').'"]')->item(0);
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

        $this->actingAs($admin)->put('/settings/account', [
            'name' => 'Ana', 'email' => 'x@example.com', 'current_password' => 'correct-horse-battery',
        ])->assertSessionHasNoErrors();
        $this->actingAs($admin->fresh())->put('/settings/password', [
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

    public function test_jobs_tab_shows_the_whole_cron_line_and_last_run_tone(): void
    {
        $admin = $this->admin();
        $line = SchedulerCron::line();

        $html = $this->actingAs($admin)->get('/settings?tab=jobs')->assertOk()->getContent();
        $x = $this->xpath($html);
        $box = $x->query('//textarea[@id="f-cron-line"]')->item(0);
        $this->assertNotNull($box, 'a wrapping block, not a one-line input');
        $this->assertSame($line, $box->textContent, 'the whole line');
        $this->assertTrue($box->hasAttribute('readonly'));
        $this->assertSame(0, $x->query('//input[@id="f-cron-line"]')->length);
        $this->assertSame('Cron line', trim($x->query('//label[@for="f-cron-line"]')->item(0)?->textContent ?? ''));
        // Copy copies exactly the line.
        $data = $x->query('//textarea[@id="f-cron-line"]/ancestor::*[@x-data][1]')->item(0)->getAttribute('x-data');
        $config = ['url' => null, 'value' => $line, 'mask' => $line, 'isSet' => true, 'name' => null];
        $this->assertSame('credentialField('.Js::from($config).')', $data);
        // One heading only: the tab's name; no repeated "Scheduler" heading, no unused live region.
        $this->assertStringNotContainsString('Scheduler</h2>', $html);
        $this->assertStringNotContainsString('credential-status', $html);
        $css = (string) file_get_contents(resource_path('css/blotter-components.css'));
        $this->assertMatchesRegularExpression('/\.x-copyvalue__text\s*\{[^}]*white-space:\s*pre-wrap;[^}]*overflow-wrap:\s*anywhere;/s', $css);

        $this->actingAs($admin)->get('/settings?tab=jobs')->assertSeeInOrder(['Last run', 'x-badge--mute', 'Never']);
        app(Settings::class)->set(ScheduleHeartbeat::KEY, now()->subSeconds(90)->toIso8601String());
        $this->actingAs($admin)->get('/settings?tab=jobs')->assertSeeInOrder(['Last run', 'x-badge--good', '1 min ago']);
        app(Settings::class)->set(ScheduleHeartbeat::KEY, now()->subMinutes(4)->toIso8601String());
        $this->actingAs($admin)->get('/settings?tab=jobs')->assertSeeInOrder(['Last run', 'x-badge--attn', '4 min ago']);
    }

    public function test_every_control_is_44px_on_phones(): void
    {
        $css = (string) file_get_contents(resource_path('css/pages/settings.css'));
        $this->assertMatchesRegularExpression('/@media \(max-width: 639px\)\s*\{\s*\.st-panel \.x-input:not\(textarea\),\s*\.st-panel \.x-btn\s*\{\s*min-height:\s*44px;/s', $css);
        foreach (['.st-switch', '.st-copy--short', '.st-jobs'] as $dead) {
            $this->assertStringNotContainsString($dead, $css);
        }
        // The address forms still rely on these.
        $this->assertStringContainsString('.st-row .x-phone', $css);
        $this->assertStringContainsString('.st-row .ph-addr', $css);
    }

    private function xpath(string $html): \DOMXPath
    {
        $doc = new \DOMDocument;
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?>'.$html);
        libxml_clear_errors();

        return new \DOMXPath($doc);
    }

    /** @return array<string, string> */
    private function general(array $extra = []): array
    {
        return ['tab' => 'general', 'shipments_reference_prefix' => 'VS'] + $extra;
    }

    public function test_general_has_no_sender_section_and_ignores_posted_sender_fields(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->get('/settings')->assertOk()
            ->assertDontSee('Sender')->assertDontSee('pickup_contact_name', false)->assertDontSee('pickup_addr', false);

        $this->actingAs($admin)->put('/settings', $this->general(['pickup_contact_name' => 'Ana Reyes', 'pickup_addr' => ['city' => 'Makati']]))
            ->assertRedirect('/settings?tab=general')->assertSessionHasNoErrors();
        $this->assertSame(0, Setting::query()->where('key', 'like', 'pickup.%')->count());
        $this->assertSame(0, AuditEntry::query()->where('subject_id', 'like', 'pickup.%')->count());
    }
}
