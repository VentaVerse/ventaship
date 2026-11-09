<?php

namespace Tests\Feature\Ui;

use App\Couriers\AccountSettings;
use App\Couriers\CourierRegistry;
use App\Couriers\FakeCourier;
use App\Models\Admin;
use App\Models\AuditEntry;
use App\Models\CourierAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CourierAccountShowTest extends TestCase
{
    use RefreshDatabase;

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

    private function account(string $courier, array $attrs = []): CourierAccount
    {
        return CourierAccount::factory()->create(['courier' => $courier, 'mode' => 'staging', ...$attrs]);
    }

    public function test_show_renders_sections_per_courier(): void
    {
        $admin = $this->admin();

        $qx = $this->account('quadx', ['is_default' => true, 'default_slot' => 'quadx', 'last_check_ok' => true]);
        $html = $this->actingAs($admin)->get(route('accounts.show', $qx->courier))->assertOk()
            ->assertSee('QuadX')->assertSee('Staging')->assertSee('Connected')
            ->assertSee('Test connection')->assertSee('Account')->assertSee('Booking defaults')
            ->assertSee('Default for QuadX')->assertSee('Default schedule')->assertSee('Default parcel')
            ->assertSee('Discard')->assertSee('Save')
            ->getContent();
        $this->assertStringContainsString('role="radiogroup"', $html);
        $this->assertStringContainsString('id="account-mode"', $html);
        $this->assertSame(2, substr_count($html, 'type="radio"'));
        $this->assertStringNotContainsString('Allow live booking', $html);
        $this->assertStringNotContainsString('name="settings[quadx_staging_api_key]"', $html);

        $spx = $this->account('spx', ['mode' => 'uat']);
        $html = $this->actingAs($admin)->get(route('accounts.show', $spx->courier))->assertOk()
            ->assertDontSee('Allow live booking')->assertSee('UAT')->assertSee('App ID')->assertSee('User ID')->assertSee('Pouch presets')
            ->getContent();
        $this->assertSame(3, substr_count($html, 'type="radio"'));
        $this->assertStringNotContainsString('name="settings[spx_secret_key]"', $html);

        $lbc = $this->account('lbc');
        $html = $this->actingAs($admin)->get(route('accounts.show', $lbc->courier))->assertOk()
            ->assertDontSee('Allow live booking')->assertSee('Client code')->assertSee('Shipper Account No')->assertSee('API gateway')
            ->getContent();
        $this->assertStringNotContainsString('lbc_mode', $html);
        $this->assertStringNotContainsString('Key set', $html);
        $this->assertStringNotContainsString('name="settings[lbc_test_common_key]"', $html);
    }

    public function test_inactive_account_shows_inactive_badge_in_list(): void
    {
        $this->account('quadx', ['status' => 'inactive']);
        $this->actingAs($this->admin())->get(route('accounts.index'))->assertSee('Inactive');
    }

    public function test_list_open_button_has_accessible_name(): void
    {
        $account = $this->account('quadx');
        $this->actingAs($this->admin())->get(route('accounts.index'))
            ->assertSee('aria-label="Open QuadX"', false)
            ->assertSee(route('accounts.show', $account->courier), false);
    }

    public function test_buttons_have_touch_targets_under_640px(): void
    {
        $css = file_get_contents(resource_path('css/pages/accounts.css'));
        $this->assertMatchesRegularExpression('/max-width:\s*640px\)\s*\{[^@]*\.ac-page \.x-btn[^}]*min-height:\s*44px/s', $css);
    }

    public function test_update_changes_mode_default_active(): void
    {
        $admin = $this->admin();
        $b = $this->account('quadx', ['label' => 'Second']);

        $this->actingAs($admin)->put(route('accounts.update', $b->courier), [
            'mode' => 'production', 'default' => '1', 'active' => '0',
            'settings' => ['quadx_default_service' => 'next_day', 'quadx_default_parcel' => 'box'],
        ])->assertRedirect(route('accounts.show', $b->courier))->assertSessionHas('status', 'Saved');

        $b->refresh();
        $this->assertSame('production', $b->mode);
        // Deactivating in the same save drops the default it was just given.
        $this->assertFalse($b->is_default);
        $this->assertNull($b->default_slot);
        $this->assertSame('inactive', $b->status);
        $this->assertSame('next_day', AccountSettings::for($b)->read('quadx_default_service'));

        foreach (['courier_account.mode_changed', 'courier_account.default_changed', 'courier_account.status_changed', 'courier_account.setting_changed'] as $action) {
            $entry = AuditEntry::query()->where('action', $action)->latest('id')->firstOrFail();
            $this->assertSame('admin', $entry->actor_type, $action);
            $this->assertSame((string) $admin->id, $entry->actor_id, $action);
        }
    }

    public function test_update_writes_only_changed_settings(): void
    {
        $a = $this->account('quadx');
        AccountSettings::for($a)->write('quadx_default_parcel', 'box');
        AuditEntry::query()->delete();

        $this->actingAs($this->admin())->put(route('accounts.update', $a->courier), [
            'mode' => 'staging', 'default' => '0', 'active' => '1',
            'settings' => ['quadx_default_parcel' => 'box', 'quadx_default_service' => ''],
        ])->assertSessionHas('status', 'Saved');

        $this->assertSame(0, AuditEntry::query()->count());
    }

    public function test_update_invalid_value_is_a_form_error(): void
    {
        $a = $this->account('quadx');

        $this->actingAs($this->admin())->from(route('accounts.show', $a->courier))->put(route('accounts.update', $a->courier), [
            'mode' => 'staging',
            'settings' => ['quadx_default_parcel' => 'nope'],
        ])->assertRedirect(route('accounts.show', $a->courier))->assertSessionHasErrors('settings.quadx_default_parcel');

        $this->actingAs($this->admin())->put(route('accounts.update', $a->courier), ['mode' => 'uat'])
            ->assertSessionHasErrors(['mode']);
    }

    public function test_update_ignores_secret_and_unknown_setting_keys(): void
    {
        $a = $this->account('quadx');

        $this->actingAs($this->admin())->put(route('accounts.update', $a->courier), [
            'mode' => 'staging',
            'settings' => ['quadx_staging_api_key' => 'sneaky', 'bogus' => 'x'],
        ])->assertSessionHas('status', 'Saved');

        $this->assertNull(AccountSettings::for($a)->read('quadx_staging_api_key'));
    }

    public function test_lbc_mode_switch_syncs_key_set(): void
    {
        $a = $this->account('lbc');

        $this->actingAs($this->admin())->put(route('accounts.update', $a->courier), ['mode' => 'production'])
            ->assertSessionHas('status', 'Saved');
        $this->assertSame('live', AccountSettings::for($a)->read('lbc_mode'));

        $this->actingAs($this->admin())->put(route('accounts.update', $a->courier), ['mode' => 'staging']);
        $this->assertSame('test', AccountSettings::for($a)->read('lbc_mode'));
    }

    public function test_test_connection_success_and_timeout_flash(): void
    {
        $a = $this->account('quadx');
        $admin = $this->admin();

        CourierRegistry::fake('quadx', FakeCourier::make());
        $this->actingAs($admin)->post(route('accounts.test', $a->courier))
            ->assertRedirect(route('accounts.show', $a->courier))->assertSessionHas('status', 'Connected');
        $this->assertTrue($a->refresh()->last_check_ok);

        CourierRegistry::fake('quadx', FakeCourier::make()->throws(new ConnectionException('timeout'), 'testConnection'));
        $this->actingAs($admin)->post(route('accounts.test', $a->courier))
            ->assertRedirect(route('accounts.show', $a->courier))
            ->assertSessionHasErrors(['test' => 'Check failed: No answer from QuadX.']);
        $this->assertFalse($a->refresh()->last_check_ok);
    }

    public function test_real_adapter_timeout_flashes_short_message(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out for https://api.staging.quadx.xyz/v2/orders/statuses'));
        $a = $this->account('quadx');
        AccountSettings::for($a)->writeSecret('quadx_staging_api_key', 'qx-key-0001');
        AccountSettings::for($a)->writeSecret('quadx_staging_secret_key', 'qx-secret-0001');
        $s = $this->account('spx', ['label' => 'Spx']);
        AccountSettings::for($s)->write('spx_app_id', '424242');
        AccountSettings::for($s)->writeSecret('spx_secret_key', 'fake-app-secret');
        AccountSettings::for($s)->write('spx_user_id', '999999');
        AccountSettings::for($s)->writeSecret('spx_user_secret', 'fake-user-secret');

        $this->actingAs($this->admin())->post(route('accounts.test', $a->courier))
            ->assertSessionHasErrors(['test' => 'Check failed: No answer from QuadX.']);
        $this->assertFalse($a->refresh()->last_check_ok);

        $this->actingAs($this->admin())->post(route('accounts.test', $s->courier))
            ->assertSessionHasErrors(['test' => 'Check failed: No answer from SPX.']);
        $this->assertFalse($s->refresh()->last_check_ok);
    }

    public function test_unexpected_exception_during_save_rolls_back_and_surfaces(): void
    {
        $a = $this->account('quadx');
        $this->withoutExceptionHandling();
        $this->partialMock(\App\Couriers\CourierAccounts::class, function ($m) {
            $m->shouldReceive('setMode')->andThrow(new \InvalidArgumentException('boom'));
        });
        $level = \Illuminate\Support\Facades\DB::transactionLevel();

        try {
            $this->actingAs($this->admin())->put(route('accounts.update', $a->courier), ['mode' => 'staging']);
            $this->fail('The exception should surface.');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertSame($level, \Illuminate\Support\Facades\DB::transactionLevel());
    }

    public function test_unknown_account_404(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->get('/accounts/nope')->assertNotFound();
        $this->actingAs($admin)->put('/accounts/nope', ['mode' => 'staging'])->assertNotFound();
        $this->actingAs($admin)->post('/accounts/nope/test')->assertNotFound();
        $this->actingAs($admin)->put('/accounts/lbc', ['mode' => 'staging'])->assertNotFound();
        $this->actingAs($admin)->post('/accounts/lbc/test')->assertNotFound();
    }

    public function test_guests_are_sent_to_login(): void
    {
        $a = $this->account('quadx');
        $this->get(route('accounts.show', $a->courier))->assertRedirect();
        $this->put(route('accounts.update', $a->courier), [])->assertRedirect();
        $this->post(route('accounts.test', $a->courier))->assertRedirect();
    }

    public function test_blank_value_clears_a_stored_setting(): void
    {
        $a = $this->account('quadx');
        AccountSettings::for($a)->write('quadx_default_parcel', 'box');
        AccountSettings::for($a)->write('quadx_default_service', 'next_day');

        $this->actingAs($this->admin())->put(route('accounts.update', $a->courier), [
            'mode' => 'staging', 'settings' => ['quadx_default_parcel' => '', 'quadx_default_service' => 'next_day'],
        ])->assertSessionHasNoErrors()->assertSessionHas('status', 'Saved');

        $this->assertNull(AccountSettings::for($a)->read('quadx_default_parcel'));
        $this->assertSame('next_day', AccountSettings::for($a)->read('quadx_default_service'));
        $this->assertSame(1, AuditEntry::query()->where('action', 'courier_account.setting_changed')->where('changes->key', 'quadx_default_parcel')->where('actor_type', 'admin')->count());
    }

    public function test_save_is_atomic(): void
    {
        $a = $this->account('quadx');
        AccountSettings::for($a)->write('quadx_default_service', 'next_day');
        AuditEntry::query()->delete();

        $this->actingAs($this->admin())->put(route('accounts.update', $a->courier), [
            'mode' => 'production', 'default' => '1', 'active' => '0',
            'settings' => ['quadx_default_service' => 'same_day_pickup', 'quadx_default_parcel' => 'nope'],
        ])->assertSessionHasErrors('settings.quadx_default_parcel');

        $label = $a->label;
        $a->refresh();
        $this->assertSame($label, $a->label);
        $this->assertSame('staging', $a->mode);
        $this->assertFalse($a->is_default);
        $this->assertSame('active', $a->status);
        $this->assertSame('next_day', AccountSettings::for($a)->read('quadx_default_service'));
        $this->assertSame(0, AuditEntry::query()->count());
    }

    public function test_lbc_mode_switch_keeps_other_mode_keys_and_ignores_crafted_keys(): void
    {
        $a = $this->account('lbc', ['mode' => 'staging']);
        AccountSettings::for($a)->write('lbc_mode', 'test');
        AccountSettings::for($a)->writeSecret('lbc_test_common_key', 'test-key-1234');
        AccountSettings::for($a)->writeSecret('lbc_live_common_key', 'live-key-5678');

        $this->actingAs($this->admin())->put(route('accounts.update', $a->courier), [
            'mode' => 'production',
            'settings' => ['lbc_mode' => 'test', 'lbc_live_common_key' => 'hijack', 'lbc_test_common_key' => 'hijack'],
        ])->assertSessionHas('status', 'Saved');

        $s = AccountSettings::for($a->refresh());
        $this->assertSame('live', $s->read('lbc_mode'));
        $this->assertSame('test-key-1234', $s->secret('lbc_test_common_key'));
        $this->assertSame('live-key-5678', $s->secret('lbc_live_common_key'));

        $this->actingAs($this->admin())->put(route('accounts.update', $a->courier), ['mode' => 'staging']);
        $this->assertSame('live-key-5678', AccountSettings::for($a)->secret('lbc_live_common_key'));
    }
}
