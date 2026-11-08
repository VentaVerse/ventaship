<?php

namespace Tests\Feature\Ui;

use App\Couriers\LiveBookingBlocked;
use App\Couriers\LiveGuard;
use App\Models\Admin;
use App\Models\AuditEntry;
use App\Models\CourierAccount;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
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
        app(Settings::class)->set('safety.allow_live_spx', true);

        $this->actingAs($admin)->get('/settings')
            ->assertOk()->assertSee('value="ZZ"', false)->assertSee('value="180"', false);

        $this->actingAs($admin)->get('/settings?tab=bogus')->assertOk()->assertSee('value="ZZ"', false);

        $html = $this->actingAs($admin)->get('/settings?tab=couriers')->assertOk()
            ->assertSee('Allow live LBC bookings')->getContent();
        $this->assertMatchesRegularExpression('/name="safety_allow_live_spx"[^>]*checked/', $html);
        $this->assertDoesNotMatchRegularExpression('/type="checkbox"[^>]*name="safety_allow_live_lbc"[^>]*checked/', $html);

        $this->actingAs($admin)->get('/settings?tab=account')->assertOk()
            ->assertSee('value="ana@example.com"', false);
    }

    public function test_unchecked_safety_switch_stores_strict_false(): void
    {
        $admin = $this->admin();
        config(['couriers.lbc_live' => true]);
        app(Settings::class)->set('safety.allow_live_lbc', true);
        $account = CourierAccount::factory()->create(['courier' => 'lbc', 'mode' => 'production', 'allow_live_booking' => true]);
        LiveGuard::assertAllowed($account, 'pickup');

        $this->actingAs($admin)->put('/settings', ['tab' => 'couriers', 'safety_allow_live_lbc' => '0', 'safety_allow_live_spx' => '0'])
            ->assertRedirect('/settings?tab=couriers');

        $this->assertFalse(app(Settings::class)->get('safety.allow_live_lbc'));
        $this->assertFalse(app(Settings::class)->get('safety.allow_live_spx'));
        $this->expectException(LiveBookingBlocked::class);
        LiveGuard::assertAllowed($account, 'pickup');
    }

    public function test_missing_switch_field_also_stores_false(): void
    {
        $this->actingAs($this->admin())->put('/settings', ['tab' => 'couriers']);

        $this->assertFalse(app(Settings::class)->get('safety.allow_live_lbc'));
    }

    public function test_checked_switch_stores_true(): void
    {
        $this->actingAs($this->admin())->put('/settings', ['tab' => 'couriers', 'safety_allow_live_lbc' => '1', 'safety_allow_live_spx' => '0'])
            ->assertSessionHas('status', 'Saved');

        $this->assertTrue(app(Settings::class)->get('safety.allow_live_lbc'));
        $this->assertFalse(app(Settings::class)->get('safety.allow_live_spx'));
    }

    public function test_invalid_prefix_rejected_with_message(): void
    {
        $this->actingAs($this->admin())->from('/settings')
            ->put('/settings', ['tab' => 'general', 'shipments_reference_prefix' => 'ab-1', 'privacy_retention_days' => 180])
            ->assertRedirect('/settings')
            ->assertSessionHasErrors(['shipments_reference_prefix' => 'Use 1–8 capital letters or digits.']);

        $this->assertNull(app(Settings::class)->get('shipments.reference_prefix'));
    }

    public function test_retention_bounds(): void
    {
        $admin = $this->admin();
        foreach ([29, 3651, 'abc'] as $bad) {
            $this->actingAs($admin)->put('/settings', ['tab' => 'general', 'shipments_reference_prefix' => 'VS', 'privacy_retention_days' => $bad])
                ->assertSessionHasErrors(['privacy_retention_days' => 'Between 30 and 3650 days.']);
        }
        foreach ([30, 3650] as $ok) {
            $this->actingAs($admin)->put('/settings', ['tab' => 'general', 'shipments_reference_prefix' => 'VS', 'privacy_retention_days' => $ok])
                ->assertSessionHasNoErrors();
            $this->assertSame($ok, app(Settings::class)->get('privacy.retention_days'));
        }
    }

    public function test_only_changed_keys_audited_with_admin_actor(): void
    {
        $admin = $this->admin();
        app(Settings::class)->set('shipments.reference_prefix', 'AB');

        $this->actingAs($admin)->put('/settings', ['tab' => 'general', 'shipments_reference_prefix' => 'AB', 'privacy_retention_days' => 90]);

        $rows = AuditEntry::query()->where('action', 'setting.changed')->get();
        $this->assertCount(1, $rows);
        $row = $rows->first();
        $this->assertSame('privacy.retention_days', $row->subject_id);
        $this->assertSame('admin', $row->actor_type);
        $this->assertSame((string) $admin->id, $row->actor_id);
        $this->assertSame(['value' => ['from' => 180, 'to' => 90]], $row->changes);
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
        $this->assertSame(0, \App\Models\AuditEntry::query()->where('action', 'admin.updated')->count(), 'same email in other case is no change');

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

        $this->put('/settings/account', [
            'name' => 'Ana', 'email' => 'ana@example.com', 'current_password' => 'correct-horse-battery',
            'password' => 'a-brand-new-passphrase', 'password_confirmation' => 'a-brand-new-passphrase',
        ])->assertSessionHasNoErrors();

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
        $base = ['name' => 'Ana', 'email' => 'ana@example.com', 'current_password' => 'correct-horse-battery'];

        $this->actingAs($admin)->put('/settings/account', $base + ['password' => 'short', 'password_confirmation' => 'short'])
            ->assertSessionHasErrors(['password' => 'At least 12 characters.']);
        $this->actingAs($admin)->put('/settings/account', $base + ['password' => 'a-brand-new-passphrase', 'password_confirmation' => 'different-passphrase'])
            ->assertSessionHasErrors(['password' => "Passwords don't match."]);
    }

    public function test_audit_never_contains_password(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->put('/settings/account', [
            'name' => 'Ana', 'email' => 'x@example.com', 'current_password' => 'correct-horse-battery',
            'password' => 'a-brand-new-passphrase', 'password_confirmation' => 'a-brand-new-passphrase',
        ]);

        $row = AuditEntry::query()->where('action', 'admin.updated')->firstOrFail();
        $this->assertSame('admin', $row->actor_type);
        $this->assertSame(['email', 'password'], $row->changes['fields']);
        $dump = json_encode($row->getAttributes());
        $this->assertStringNotContainsString('a-brand-new-passphrase', $dump);
        $this->assertStringNotContainsString('correct-horse-battery', $dump);
        $this->assertStringNotContainsString($admin->fresh()->password, $dump);
    }

    /** @return array<string, string> */
    private function general(array $extra = []): array
    {
        return ['tab' => 'general', 'shipments_reference_prefix' => 'VS', 'privacy_retention_days' => '180'] + $extra;
    }

    /** @return array<string, string> */
    private function sender(array $over = []): array
    {
        $addr = $over['pickup_addr'] ?? null;
        unset($over['pickup_addr']);

        return $over + [
            'pickup_contact_name' => 'Ana Reyes', 'pickup_contact_phone' => '0917 123 4567',
            'pickup_address_1' => '12 Rizal St', 'pickup_address_2' => 'Unit 4',
            'pickup_addr' => $addr ?? ['barangay' => 'Poblacion', 'city' => 'Makati', 'state' => 'Metro Manila', 'postcode' => '1200'],
        ];
    }

    public function test_sender_section_saves_and_audits_changed_keys(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->get('/settings')->assertOk()->assertSee('Sender')->assertSee('Province');

        $this->actingAs($admin)->put('/settings', $this->general($this->sender()))->assertRedirect('/settings?tab=general');

        $settings = app(Settings::class);
        $this->assertSame('Ana Reyes', $settings->get('pickup.contact_name'));
        $this->assertSame('Metro Manila', $settings->get('pickup.state'));
        $this->assertSame(8, AuditEntry::query()->where('action', 'setting.changed')->where('subject_id', 'like', 'pickup.%')->count());

        $this->actingAs($admin)->put('/settings', $this->general($this->sender(['pickup_addr' => ['barangay' => 'Poblacion', 'city' => 'Pasig', 'state' => 'Metro Manila', 'postcode' => '1200']])));
        $this->assertSame(
            ['pickup.city'],
            AuditEntry::query()->where('action', 'setting.changed')->where('subject_id', 'like', 'pickup.%')
                ->orderByDesc('id')->limit(1)->pluck('subject_id')->all(),
        );
        $this->assertSame(9, AuditEntry::query()->where('subject_id', 'like', 'pickup.%')->count());

        $this->actingAs($admin)->get('/settings')->assertSee('value="Pasig"', false);

        $this->actingAs($admin)->put('/settings', $this->general())->assertSessionHasNoErrors();
        $this->assertNull(app(Settings::class)->pickup());
    }

    public function test_general_saves_without_sender(): void
    {
        $this->actingAs($this->admin())->put('/settings', $this->general())
            ->assertRedirect('/settings?tab=general')->assertSessionHasNoErrors();
        $this->assertNull(app(Settings::class)->pickup());
    }

    public function test_sender_validation_messages(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->from('/settings')->put('/settings', $this->general(['pickup_address_2' => 'Unit 4']))
            ->assertSessionHasErrors([
                'pickup_contact_name' => 'Required.', 'pickup_contact_phone' => 'Required.', 'pickup_address_1' => 'Required.',
                'pickup_addr.city' => 'Required.', 'pickup_addr.state' => 'Required.',
            ]);

        $this->actingAs($admin)->from('/settings')->put('/settings', $this->general($this->sender(['pickup_contact_phone' => '12345'])))
            ->assertSessionHasErrors(['pickup_contact_phone' => 'Not a valid PH mobile number.']);
        $this->actingAs($admin)->from('/settings')->put('/settings', $this->general($this->sender(['pickup_contact_name' => str_repeat('a', 256)])))
            ->assertSessionHasErrors(['pickup_contact_name' => 'Too long.']);
        $this->assertNull(app(Settings::class)->pickup());
    }

    public function test_pickup_helper_returns_api_shape(): void
    {
        $settings = app(Settings::class);
        $this->assertNull($settings->pickup());

        $this->actingAs($this->admin())->put('/settings', $this->general($this->sender(['pickup_address_2' => '', 'pickup_addr' => ['city' => 'Makati', 'state' => 'Metro Manila', 'postcode' => '1200']])));

        $this->assertSame([
            'contact_name' => 'Ana Reyes', 'contact_phone' => '0917 123 4567', 'address_1' => '12 Rizal St',
            'address_2' => null, 'barangay' => null, 'district' => null, 'city' => 'Makati', 'state' => 'Metro Manila',
            'postcode' => '1200', 'codes' => [], 'country' => 'PH',
        ], app(Settings::class)->pickup());
    }

    public function test_sender_codes_saved_and_returned_by_pickup(): void
    {
        $ph = app(\App\Support\PhAddress::class);
        $state = $ph->provinces()[0];
        $city = $ph->cities($state['code'])[0];
        $brgy = $ph->barangays($city['code'])[0];
        $addr = [
            'state' => $state['name'], 'city' => $city['name'], 'district' => '', 'barangay' => $brgy['name'], 'postcode' => '2800',
            'codes' => ['state' => $state['code'], 'city' => $city['code'], 'barangay' => $brgy['code']],
        ];
        $admin = $this->admin();

        $this->actingAs($admin)->put('/settings', $this->general($this->sender(['pickup_addr' => $addr])))->assertSessionHasNoErrors();

        $settings = app(Settings::class);
        $this->assertSame($city['code'], $settings->get('pickup.codes.city'));
        $this->assertNull($settings->get('pickup.district'));
        $pickup = $settings->pickup();
        $this->assertSame(['state' => $state['code'], 'city' => $city['code'], 'barangay' => $brgy['code']], $pickup['codes']);
        $this->assertArrayHasKey('district', $pickup);

        $html = $this->actingAs($admin)->get('/settings')->getContent();
        $this->assertStringContainsString('name="pickup_addr[codes][city]"', $html);
        $this->assertStringContainsString('value="'.$city['code'].'"', $html);

        // A code that does not belong to the posted names is refused and nothing changes.
        $bad = ['codes' => ['state' => $state['code'], 'city' => 'bogus', 'barangay' => $brgy['code']]] + $addr;
        $this->actingAs($admin)->from('/settings')->put('/settings', $this->general($this->sender(['pickup_addr' => $bad])))
            ->assertSessionHasErrors(['pickup_addr.state' => 'Choose from the list.']);
        $this->assertSame($city['code'], $settings->pickup()['codes']['city']);

        // Clearing everything unsets the sender, codes included.
        $this->actingAs($admin)->put('/settings', $this->general(['pickup_addr' => ['codes' => ['state' => '', 'city' => '', 'barangay' => '']]]))
            ->assertSessionHasNoErrors();
        $this->assertNull(app(Settings::class)->pickup());
        $this->assertNull(app(Settings::class)->get('pickup.codes.city'));
    }

    public function test_empty_sender_writes_no_pickup_audit_rows(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->put('/settings', $this->general())->assertSessionHasNoErrors();
        $this->actingAs($admin)->put('/settings', $this->general())->assertSessionHasNoErrors();

        $this->assertSame(0, AuditEntry::query()->where('subject_id', 'like', 'pickup.%')->count());
        $this->assertNull(app(Settings::class)->pickup());
    }

    public function test_pickup_helper_is_null_without_phone(): void
    {
        $settings = app(Settings::class);
        foreach (['contact_name' => 'Ana', 'address_1' => '12 Rizal', 'city' => 'Makati', 'state' => 'NCR'] as $k => $v) {
            $settings->set("pickup.{$k}", $v);
        }
        $this->assertNull($settings->pickup());
    }
}
