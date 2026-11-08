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
        return CourierAccount::factory()->create(['courier' => $courier, 'label' => 'Main', 'mode' => 'staging', ...$attrs]);
    }

    public function test_show_renders_sections_per_courier(): void
    {
        $admin = $this->admin();

        $qx = $this->account('quadx', ['is_default' => true, 'default_slot' => 'quadx', 'last_check_ok' => true]);
        $html = $this->actingAs($admin)->get(route('accounts.show', $qx))->assertOk()
            ->assertSee('QuadX · Main')->assertSee('Staging')->assertSee('Connected')
            ->assertSee('Test connection')->assertSee('Account')->assertSee('Booking defaults')
            ->assertSee('Default for QuadX')->assertSee('Default schedule')->assertSee('Default parcel')
            ->assertSee('Discard')->assertSee('Save')
            ->getContent();
        $this->assertStringContainsString('role="radiogroup"', $html);
        $this->assertStringContainsString('id="account-mode"', $html);
        $this->assertSame(2, substr_count($html, 'type="radio"'));
        $this->assertStringNotContainsString('Allow live booking', $html);
        $this->assertStringNotContainsString('quadx_staging_api_key', $html);

        $spx = $this->account('spx', ['mode' => 'uat']);
        $html = $this->actingAs($admin)->get(route('accounts.show', $spx))->assertOk()
            ->assertSee('Allow live booking')->assertSee('UAT')->assertSee('App ID')->assertSee('User ID')->assertSee('Pouch presets')
            ->getContent();
        $this->assertSame(3, substr_count($html, 'type="radio"'));
        $this->assertStringNotContainsString('spx_secret_key', $html);

        $lbc = $this->account('lbc');
        $html = $this->actingAs($admin)->get(route('accounts.show', $lbc))->assertOk()
            ->assertSee('Allow live booking')->assertSee('Client code')->assertSee('Shipper Account No')->assertSee('API gateway')
            ->getContent();
        $this->assertStringNotContainsString('lbc_mode', $html);
        $this->assertStringNotContainsString('Key set', $html);
        $this->assertStringNotContainsString('lbc_test_common_key', $html);
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
            ->assertSee('aria-label="Open QuadX · Main"', false)
            ->assertSee(route('accounts.show', $account), false);
    }

    public function test_buttons_have_touch_targets_under_640px(): void
    {
        $css = file_get_contents(resource_path('css/pages/accounts.css'));
        $this->assertMatchesRegularExpression('/max-width:\s*640px\)\s*\{[^@]*\.ac-page \.x-btn[^}]*min-height:\s*44px/s', $css);
    }

    public function test_update_changes_name_mode_default_active(): void
    {
        $admin = $this->admin();
        $a = $this->account('quadx', ['is_default' => true, 'default_slot' => 'quadx']);
        $b = $this->account('quadx', ['label' => 'Second']);

        $this->actingAs($admin)->put(route('accounts.update', $b), [
            'label' => 'Renamed', 'mode' => 'production', 'default' => '1', 'active' => '0',
            'settings' => ['quadx_default_service' => 'next_day', 'quadx_default_parcel' => 'box'],
        ])->assertRedirect(route('accounts.show', $b))->assertSessionHas('status', 'Saved');

        $b->refresh();
        $a->refresh();
        $this->assertSame('Renamed', $b->label);
        $this->assertSame('production', $b->mode);
        $this->assertTrue($b->is_default);
        $this->assertSame('quadx', $b->default_slot);
        $this->assertSame('inactive', $b->status);
        $this->assertFalse($a->is_default);
        $this->assertNull($a->default_slot);
        $this->assertSame('next_day', AccountSettings::for($b)->read('quadx_default_service'));
        $this->assertFalse($b->allow_live_booking);

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

        $this->actingAs($this->admin())->put(route('accounts.update', $a), [
            'label' => 'Main', 'mode' => 'staging', 'default' => '0', 'active' => '1',
            'settings' => ['quadx_default_parcel' => 'box', 'quadx_default_service' => ''],
        ])->assertSessionHas('status', 'Saved');

        $this->assertSame(0, AuditEntry::query()->count());
    }

    public function test_update_invalid_value_is_a_form_error(): void
    {
        $a = $this->account('quadx');

        $this->actingAs($this->admin())->from(route('accounts.show', $a))->put(route('accounts.update', $a), [
            'label' => 'Main', 'mode' => 'staging',
            'settings' => ['quadx_default_parcel' => 'nope'],
        ])->assertRedirect(route('accounts.show', $a))->assertSessionHasErrors('settings.quadx_default_parcel');

        $this->actingAs($this->admin())->put(route('accounts.update', $a), ['label' => '', 'mode' => 'uat'])
            ->assertSessionHasErrors(['label', 'mode']);
    }

    public function test_update_ignores_secret_and_unknown_setting_keys(): void
    {
        $a = $this->account('quadx');

        $this->actingAs($this->admin())->put(route('accounts.update', $a), [
            'label' => 'Main', 'mode' => 'staging',
            'settings' => ['quadx_staging_api_key' => 'sneaky', 'bogus' => 'x'],
        ])->assertSessionHas('status', 'Saved');

        $this->assertNull(AccountSettings::for($a)->read('quadx_staging_api_key'));
    }

    public function test_lbc_mode_switch_syncs_key_set(): void
    {
        $a = $this->account('lbc');

        $this->actingAs($this->admin())->put(route('accounts.update', $a), ['label' => 'Main', 'mode' => 'production'])
            ->assertSessionHas('status', 'Saved');
        $this->assertSame('live', AccountSettings::for($a)->read('lbc_mode'));
        $this->assertFalse($a->refresh()->allow_live_booking);

        $this->actingAs($this->admin())->put(route('accounts.update', $a), ['label' => 'Main', 'mode' => 'staging']);
        $this->assertSame('test', AccountSettings::for($a)->read('lbc_mode'));
    }

    public function test_allow_live_only_for_lbc_spx(): void
    {
        $admin = $this->admin();
        $qx = $this->account('quadx');
        $spx = $this->account('spx');

        $this->actingAs($admin)->put(route('accounts.update', $qx), ['label' => 'Main', 'mode' => 'staging', 'allow_live_booking' => '1']);
        $this->assertFalse($qx->refresh()->allow_live_booking);

        $this->actingAs($admin)->put(route('accounts.update', $spx), ['label' => 'Main', 'mode' => 'staging', 'allow_live_booking' => '1']);
        $this->assertTrue($spx->refresh()->allow_live_booking);
        $entry = AuditEntry::query()->where('action', 'courier_account.live_latch_changed')->firstOrFail();
        $this->assertSame('admin', $entry->actor_type);
        $this->assertSame($spx->public_id, $entry->subject_id);

        $this->actingAs($admin)->put(route('accounts.update', $spx), ['label' => 'Main', 'mode' => 'staging', 'allow_live_booking' => '0']);
        $this->assertFalse($spx->refresh()->allow_live_booking);
    }

    public function test_test_connection_success_and_timeout_flash(): void
    {
        $a = $this->account('quadx');
        $admin = $this->admin();

        CourierRegistry::fake('quadx', FakeCourier::make());
        $this->actingAs($admin)->post(route('accounts.test', $a))
            ->assertRedirect(route('accounts.show', $a))->assertSessionHas('status', 'Connected');
        $this->assertTrue($a->refresh()->last_check_ok);

        CourierRegistry::fake('quadx', FakeCourier::make()->throws(new ConnectionException('timeout'), 'testConnection'));
        $this->actingAs($admin)->post(route('accounts.test', $a))
            ->assertRedirect(route('accounts.show', $a))
            ->assertSessionHasErrors(['test' => 'Check failed: No answer from QuadX.']);
        $this->assertFalse($a->refresh()->last_check_ok);
    }

    public function test_unknown_account_404(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->get('/accounts/nope')->assertNotFound();
        $this->actingAs($admin)->put('/accounts/nope', ['label' => 'x', 'mode' => 'staging'])->assertNotFound();
        $this->actingAs($admin)->post('/accounts/nope/test')->assertNotFound();
    }

    public function test_guests_are_sent_to_login(): void
    {
        $a = $this->account('quadx');
        $this->get(route('accounts.show', $a))->assertRedirect();
        $this->put(route('accounts.update', $a), [])->assertRedirect();
        $this->post(route('accounts.test', $a))->assertRedirect();
    }
}
