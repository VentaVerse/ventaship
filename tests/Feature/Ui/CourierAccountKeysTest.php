<?php

namespace Tests\Feature\Ui;

use App\Couriers\AccountSettings;
use App\Couriers\CourierAccounts;
use App\Couriers\CourierRegistry;
use App\Models\Admin;
use App\Models\AuditEntry;
use App\Models\CourierAccount;
use App\Models\CourierAccountSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CourierAccountKeysTest extends TestCase
{
    use RefreshDatabase;

    private const STAGING_KEY = 'sk_stg_8Hq2LmZ0wXv93KpT7c1f';

    private const LIVE_KEY = 'pk_live_Zr4Nw8Yq1Tx6Vb2Mm0aa9d2e';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        CourierRegistry::clearFakes();
        parent::tearDown();
    }

    private ?Admin $admin = null;

    private function admin(): Admin
    {
        return $this->admin ??= Admin::query()->create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'correct-horse-battery']);
    }

    private function account(string $courier = 'quadx', array $attrs = []): CourierAccount
    {
        return CourierAccount::factory()->create(['courier' => $courier, 'label' => 'Main', 'mode' => 'sandbox', ...$attrs]);
    }

    private function withKeys(CourierAccount $account): CourierAccount
    {
        $svc = app(CourierAccounts::class);
        $svc->setSetting($account, 'quadx_sandbox_api_key', self::STAGING_KEY);
        $svc->setSetting($account, 'quadx_production_api_key', self::LIVE_KEY);

        return $account;
    }

    /** @return array<string, mixed> */
    private function form(CourierAccount $account, array $extra = []): array
    {
        return ['mode' => $account->mode, ...$extra];
    }

    private function auditText(): string
    {
        return AuditEntry::query()->get()->map(fn ($e) => json_encode($e->changes) . ' ' . $e->action)->implode("\n");
    }

    public function test_page_never_contains_secret_plaintext(): void
    {
        $a = $this->withKeys($this->account());
        $cipher = CourierAccountSetting::query()->where('courier_account_id', $a->id)->where('key', 'quadx_sandbox_api_key')->value('value');

        $html = $this->actingAs($this->admin())->get(route('accounts.show', $a->courier))->assertOk()
            ->assertDontSee(self::STAGING_KEY)->assertDontSee(self::LIVE_KEY)
            ->assertDontSee($cipher)
            ->assertSee('7c1f')->assertSee('9d2e')
            ->getContent();

        $this->assertStringNotContainsString(substr(self::STAGING_KEY, 0, 12), $html);
        $this->assertStringNotContainsString(substr(self::LIVE_KEY, 0, 12), $html);
        $this->assertStringContainsString('••••••••••••••••7c1f', $html);
    }

    public function test_reveal_returns_value_no_store_and_audits_key_only(): void
    {
        $a = $this->withKeys($this->account());
        $admin = $this->admin();

        $res = $this->actingAs($admin)->postJson(route('accounts.reveal', [$a->courier, 'quadx_sandbox_api_key']))
            ->assertOk()->assertExactJson(['value' => self::STAGING_KEY]);
        $this->assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));
        $this->assertSame('no-cache', $res->headers->get('Pragma'));

        $entry = AuditEntry::query()->where('action', 'courier_account.secret_viewed')->sole();
        $this->assertSame(['key' => 'quadx_sandbox_api_key'], $entry->changes);
        $this->assertSame('admin', $entry->actor_type);
        $this->assertSame((string) $admin->id, $entry->actor_id);
        $this->assertSame($a->public_id, $entry->subject_id);
        $this->assertStringNotContainsString(self::STAGING_KEY, $this->auditText());
        $this->assertStringNotContainsString('7c1f', $this->auditText());

        // Guests never reach it.
        auth()->logout();
        $this->postJson(route('accounts.reveal', [$a->courier, 'quadx_sandbox_api_key']))->assertUnauthorized();
    }

    public function test_reveal_throttled(): void
    {
        $a = $this->withKeys($this->account());
        $url = route('accounts.reveal', [$a->courier, 'quadx_sandbox_api_key']);

        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($this->admin())->postJson($url)->assertOk();
        }
        $res = $this->actingAs($this->admin())->postJson($url)->assertStatus(429);
        $this->assertMatchesRegularExpression('/^Too many views\. Try again in [1-9]\d* seconds\.$/', $res->json('message'));
        $this->assertArrayNotHasKey('value', $res->json());
        $this->assertSame(10, AuditEntry::query()->where('action', 'courier_account.secret_viewed')->count());

        // Per admin: another admin is not held up.
        $other = Admin::query()->create(['name' => 'Ben', 'email' => 'ben@example.com', 'password' => 'correct-horse-battery']);
        $this->flushSession();
        $this->actingAs($other)->postJson($url)->assertOk();
    }

    public function test_reveal_rejects_plain_or_unknown_key(): void
    {
        $a = $this->withKeys($this->account());
        $svc = app(CourierAccounts::class);
        $svc->setSetting($a, 'quadx_default_service', $svc->schema($a)[4]['options'][0]['value']);
        $this->actingAs($this->admin());

        $this->postJson(route('accounts.reveal', [$a->courier, 'quadx_default_service']))->assertNotFound()->assertJsonMissing(['value']);
        $this->postJson(route('accounts.reveal', [$a->courier, 'nope']))->assertNotFound();
        $this->postJson(route('accounts.reveal', [$a->courier, 'spx_sandbox_secret_key']))->assertNotFound();
        $this->postJson(route('accounts.reveal', [$a->courier, 'quadx_sandbox_secret_key']))->assertNotFound(); // not set
        $this->post(route('accounts.reveal', ['missing', 'quadx_sandbox_api_key']))->assertNotFound();
        $this->get('/accounts/' . $a->courier . '/secrets/quadx_sandbox_api_key/reveal')->assertStatus(405);

        $this->assertSame(0, AuditEntry::query()->where('action', 'courier_account.secret_viewed')->count());
    }

    public function test_untouched_masked_secret_unchanged_on_save(): void
    {
        $a = $this->withKeys($this->account());
        $before = CourierAccountSetting::query()->where('courier_account_id', $a->id)->pluck('value', 'key')->all();
        $audits = AuditEntry::query()->count();

        $this->actingAs($this->admin())->put(route('accounts.update', $a->courier), $this->form($a, [
            'secrets' => ['quadx_sandbox_api_key' => '', 'quadx_production_api_key' => null],
        ]))->assertRedirect(route('accounts.show', $a->courier))->assertSessionHas('status', 'Saved');

        $after = CourierAccountSetting::query()->where('courier_account_id', $a->id)->pluck('value', 'key')->all();
        $this->assertSame($before, $after);
        $settings = AccountSettings::for($a->fresh());
        $this->assertSame(self::STAGING_KEY, $settings->secret('quadx_sandbox_api_key'));
        $this->assertSame(self::LIVE_KEY, $settings->secret('quadx_production_api_key'));
        $this->assertSame(0, AuditEntry::query()->where('id', '>', $audits)->where('action', 'courier_account.setting_changed')->count());
    }

    public function test_edited_secret_saved_encrypted(): void
    {
        $a = $this->withKeys($this->account());
        $admin = $this->admin();
        $new = 'new_live_secret_Q9w8E7r6T5y4';

        $this->actingAs($admin)->put(route('accounts.update', $a->courier), $this->form($a, [
            'secrets' => [
                'quadx_production_secret_key' => $new,
                'quadx_default_service' => 'injected',
                'spx_sandbox_secret_key' => 'other-courier',
                'nope' => 'unknown',
            ],
        ]))->assertRedirect(route('accounts.show', $a->courier))->assertSessionHas('status', 'Saved');

        $row = CourierAccountSetting::query()->where('courier_account_id', $a->id)->where('key', 'quadx_production_secret_key')->value('value');
        $this->assertNotNull($row);
        $this->assertStringNotContainsString($new, $row);
        $settings = AccountSettings::for($a->fresh());
        $this->assertSame($new, $settings->secret('quadx_production_secret_key'));
        $this->assertSame(self::STAGING_KEY, $settings->secret('quadx_sandbox_api_key'));
        $this->assertNull($settings->read('quadx_default_service'));
        $this->assertNull($settings->read('spx_sandbox_secret_key'));
        $this->assertNull($settings->read('nope'));

        $entry = AuditEntry::query()->where('action', 'courier_account.setting_changed')->where('changes->key', 'quadx_production_secret_key')->sole();
        $this->assertSame('admin', $entry->actor_type);
        $this->assertSame((string) $admin->id, $entry->actor_id);
        $this->assertStringNotContainsString($new, $this->auditText());

        // The new value is masked on the page, never printed.
        $this->get(route('accounts.show', $a->courier))->assertDontSee($new)->assertSee('T5y4');
    }

    public function test_validation_error_does_not_echo_secret(): void
    {
        $a = $this->withKeys($this->account());
        $secret = 'brand_new_secret_Kp3Lm9Zz1234';
        $this->actingAs($this->admin());

        // A request validation error (bad mode).
        $this->from(route('accounts.show', $a->courier))->put(route('accounts.update', $a->courier), $this->form($a, [
            'mode' => 'bogus',
            'secrets' => ['quadx_sandbox_secret_key' => $secret],
        ]))->assertRedirect(route('accounts.show', $a->courier))->assertSessionHasErrors('mode');
        $this->assertFalse(session()->hasOldInput('secrets'));
        $this->assertStringNotContainsString($secret, json_encode(session()->all()));
        $this->get(route('accounts.show', $a->courier))->assertOk()->assertDontSee($secret)->assertDontSee('1234');

        // A refusal from the service (a bad booking default) rolls back the edited secret too.
        $this->from(route('accounts.show', $a->courier))->put(route('accounts.update', $a->courier), $this->form($a, [
            'settings' => ['quadx_default_service' => 'bogus'],
            'secrets' => ['quadx_sandbox_secret_key' => $secret],
        ]))->assertRedirect(route('accounts.show', $a->courier))->assertSessionHasErrors('settings.quadx_default_service');
        $this->assertFalse(session()->hasOldInput('secrets'));
        $this->assertStringNotContainsString($secret, json_encode(session()->all()));
        $this->get(route('accounts.show', $a->courier))->assertOk()->assertDontSee($secret);
        $this->assertNull(AccountSettings::for($a->fresh())->read('quadx_sandbox_secret_key'));

        // A secret over the length limit is refused without being echoed.
        $long = str_repeat('x', CourierAccounts::MAX_VALUE_LENGTH) . 'TAIL';
        $this->from(route('accounts.show', $a->courier))->put(route('accounts.update', $a->courier), $this->form($a, [
            'secrets' => ['quadx_sandbox_secret_key' => $long],
        ]))->assertRedirect(route('accounts.show', $a->courier))->assertSessionHasErrors('secrets.quadx_sandbox_secret_key');
        $this->assertStringNotContainsString($long, json_encode(session()->all()));
        $this->get(route('accounts.show', $a->courier))->assertOk()->assertDontSee($long);
    }

    public function test_only_the_selected_environment_keys_are_visible(): void
    {
        $admin = $this->admin();

        $qx = $this->withKeys($this->account());
        $html = $this->actingAs($admin)->get(route('accounts.show', $qx->courier))->assertOk()
            ->assertSee('Sandbox keys')->assertSee('Production keys')->assertSee('Not set')
            ->getContent();
        $this->assertDoesNotMatchRegularExpression('/data-keys="sandbox"[^>]*\shidden[\s>]/', $html);
        $this->assertMatchesRegularExpression('/data-keys="production"[^>]*\shidden[\s>]/', $html);
        $this->assertSame(2, substr_count($html, 'x-model="env"'));
        $this->assertMatchesRegularExpression('/data-env-badge[^>]*>(?:\s|<[^>]+>)*Sandbox/', $html);
        foreach (['View Sandbox API Key', 'Edit Sandbox API Key', 'Hide Sandbox API Key', 'Cancel Sandbox API Key', 'Edit Production Secret Key', 'Cancel Production Secret Key'] as $label) {
            $this->assertStringContainsString('aria-label="' . $label . '"', $html);
        }
        $this->assertStringNotContainsString('aria-label="View Production Secret Key"', $html); // not set: Edit only
        $this->assertSame(1, substr_count($html, 'aria-live="polite"'));
        $this->assertStringContainsString(route('accounts.reveal', [$qx->courier, 'quadx_sandbox_api_key']), stripslashes(stripslashes($html)));
        // Untouched fields post nothing: no secrets[] name is rendered by the server.
        $this->assertStringNotContainsString('name="secrets[', $html);
        $this->assertStringNotContainsString('name="settings[quadx_sandbox_api_key]"', $html);
        foreach (['Staging', 'UAT', 'Live', 'Allow live', 'Default for', '>Active<', 'Public address'] as $gone) {
            $this->assertStringNotContainsString($gone, $html);
        }

        $lbc = $this->account('lbc', ['mode' => 'production']);
        $html = $this->get(route('accounts.show', $lbc->courier))->assertOk()->assertSee('Common API key')->assertSee('Track and Trace key')->getContent();
        $this->assertDoesNotMatchRegularExpression('/data-keys="production"[^>]*\shidden[\s>]/', $html);
        $this->assertMatchesRegularExpression('/data-keys="sandbox"[^>]*\shidden[\s>]/', $html);
        $this->assertMatchesRegularExpression('/data-env-badge[^>]*>(?:\s|<[^>]+>)*Production/', $html);
        $this->assertStringContainsString('aria-label="Edit Production Common API key"', $html);
        $this->assertStringContainsString('aria-label="Edit Sandbox Common API key"', $html);

        $spx = $this->account('spx', ['mode' => 'sandbox']);
        AccountSettings::for($spx)->write('spx_sandbox_app_id', '424242');
        $html = $this->get(route('accounts.show', $spx->courier))->assertOk()->assertSee('App Secret')->assertSee('User Secret')->getContent();
        $this->assertMatchesRegularExpression('/data-keys="production"[^>]*\shidden[\s>]/', $html);
        $this->assertStringContainsString('aria-label="Edit Sandbox App Secret"', $html);
        $this->assertMatchesRegularExpression('/name="settings\[spx_sandbox_app_id\]"[^>]*value="424242"|value="424242"[^>]*name="settings\[spx_sandbox_app_id\]"/', $html);
        foreach (['spx_sandbox_user_id', 'spx_production_app_id', 'spx_production_user_id'] as $key) {
            $this->assertStringContainsString('name="settings[' . $key . ']"', $html);
        }
        $this->assertSame(2, substr_count($html, 'x-model="env"'));
    }

    public function test_switching_to_production_asks_through_the_confirm_dialog(): void
    {
        $qx = $this->account();
        $html = $this->actingAs($this->admin())->get(route('accounts.show', $qx->courier))->assertOk()->getContent();

        $this->assertStringContainsString('id="account-save-confirmed"', $html);
        $this->assertStringContainsString("\$dispatch('confirm'", $html);
        $this->assertStringContainsString('Switch QuadX to Production? Bookings will be real.', $html);
        $this->assertStringContainsString('Switch to Production', $html);
        $this->assertStringNotContainsString('confirm(', str_replace(["dispatch('confirm'", 'x-ui.confirm'], '', $html));
    }

    public function test_environment_ids_save_as_plain_settings_and_the_spx_webhook_ref_follows(): void
    {
        $spx = $this->account('spx', ['mode' => 'sandbox']);

        $this->actingAs($this->admin())->put(route('accounts.update', $spx->courier), $this->form($spx, [
            'settings' => ['spx_sandbox_app_id' => '111', 'spx_production_app_id' => '222', 'spx_sandbox_secret_key' => 'sneaky'],
            'secrets' => ['spx_production_secret_key' => 'prod-secret'],
        ]))->assertSessionHas('status', 'Saved');

        $settings = AccountSettings::for($spx);
        $this->assertSame('111', $settings->read('spx_sandbox_app_id'));
        $this->assertSame('222', $settings->read('spx_production_app_id'));
        $this->assertNull($settings->read('spx_sandbox_secret_key'), 'a secret is never taken from settings[]');
        $this->assertSame('prod-secret', $settings->secret('spx_production_secret_key'));
        $this->assertSame('111', $spx->fresh()->external_ref);

        $this->put(route('accounts.update', $spx->courier), ['mode' => 'production'])->assertSessionHas('status', 'Saved');
        $this->assertSame('production', $spx->fresh()->mode);
        $this->assertSame('222', $spx->fresh()->external_ref);
    }
}
