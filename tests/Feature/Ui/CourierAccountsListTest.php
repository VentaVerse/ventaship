<?php

namespace Tests\Feature\Ui;

use App\Couriers\CourierAccountException;
use App\Couriers\CourierAccounts;
use App\Models\Admin;
use App\Models\AuditEntry;
use App\Models\CourierAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class CourierAccountsListTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Admin
    {
        return Admin::query()->create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'correct-horse-battery']);
    }

    public function test_list_has_exactly_three_rows(): void
    {
        CourierAccount::factory()->create(['courier' => 'spx', 'label' => 'Branch', 'mode' => 'production', 'last_check_ok' => null]);
        CourierAccount::factory()->create(['courier' => 'quadx', 'label' => 'Main account', 'mode' => 'sandbox', 'is_default' => true, 'default_slot' => 'quadx', 'last_check_ok' => true]);

        $html = $this->actingAs($this->admin())->get(route('accounts.index'))->assertOk()
            ->assertDontSee('Connect courier')
            ->assertSeeInOrder(['QuadX', 'Sandbox', 'Connected', 'SPX', 'Production', 'Not checked', 'LBC', 'Set up'])
            ->assertSee(route('accounts.show', 'lbc'), false)
            ->getContent();

        $this->assertSame(3, preg_match_all('/<li class="ac-row[" ]/', $html));
        $this->assertStringContainsString('Not set up', $html);
        // A set-up account is edited with the pencil; there is no Open button.
        $this->assertSame(0, substr_count($html, '>Open</a>'));
        $this->assertSame(2, preg_match_all('#<a class="x-view" href="[^"]+" aria-label="Edit (QuadX|SPX)"><svg#', $html));
        $this->assertSame(1, substr_count($html, '>Set up</a>'));
    }

    public function test_list_marks_failed_check_and_live_mode(): void
    {
        CourierAccount::factory()->create(['courier' => 'quadx', 'label' => 'Main', 'mode' => 'production', 'last_check_ok' => false]);

        $html = $this->actingAs($this->admin())->get(route('accounts.index'))
            ->assertSee('Production')->assertSee('Check failed')->assertDontSee('Live')
            ->assertSee('aria-label="Fix QuadX"', false)->getContent();

        // Live is amber, never the green that means "the check passed".
        $this->assertMatchesRegularExpression('/x-badge--attn[^>]*>\s*<span[^>]*><\/span>\s*<span class="x-badge__label">Production</', $html);
        $this->assertStringContainsString('x-btn--danger-outline', $html);
    }

    public function test_list_shows_when_each_courier_was_checked(): void
    {
        CourierAccount::factory()->create(['courier' => 'quadx', 'label' => 'Main', 'mode' => 'sandbox', 'last_check_ok' => true, 'last_check_at' => '2026-11-09 14:14:00']);

        $this->actingAs($this->admin())->get(route('accounts.index'))
            ->assertSee('Checked')->assertSee('Nov 9, 2:14 PM');
    }

    public function test_show_without_account_offers_the_form_straight_away(): void
    {
        $html = $this->actingAs($this->admin())->get(route('accounts.show', 'spx'))->assertOk()
            ->assertSee('SPX')->assertSee('Sandbox')->assertSee('Production')->assertSee('Sandbox keys')->assertSee('App Secret')
            ->assertSee('Booking defaults')->assertSee('Save and test')
            ->assertDontSee('UAT')->assertDontSee('Staging')->assertDontSee('Test connection')->assertDontSee('>Set up<', false)
            ->assertDontSee('Webhook')
            ->getContent();
        $this->assertStringContainsString('action="'.route('accounts.update', 'spx').'"', $html);
        $this->assertMatchesRegularExpression('/value="sandbox"[^>]*checked/', $html);
        $this->assertStringContainsString('name="secrets[spx_sandbox_secret_key]"', $html);
        $this->assertFalse(Route::has('accounts.setup'));
    }

    public function test_first_save_creates_default_account_named_after_courier(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('accounts.update', 'quadx'), ['mode' => 'sandbox'])
            ->assertRedirect(route('accounts.show', 'quadx'))->assertSessionHas('status', 'Saved');

        $account = CourierAccount::query()->firstOrFail();
        $this->assertSame('QuadX', $account->label);
        $this->assertSame('quadx', $account->courier);
        $this->assertTrue($account->is_default);
        $audit = AuditEntry::query()->where('action', 'courier_account.created')->firstOrFail();
        $this->assertSame((string) $admin->id, $audit->actor_id);

        // A second save updates the one account.
        $this->put(route('accounts.update', 'quadx'), ['mode' => 'production'])->assertSessionHas('status', 'Saved');
        $this->assertSame(1, CourierAccount::query()->count());
        $this->assertSame('production', $account->fresh()->mode);

        $this->put(route('accounts.update', 'spx'), ['mode' => 'sandbox'])->assertRedirect();
        $this->assertSame('sandbox', CourierAccount::query()->where('courier', 'spx')->value('mode'));
        foreach (['uat', 'staging'] as $old) {
            $this->put(route('accounts.update', 'lbc'), ['mode' => $old])->assertSessionHasErrors('mode');
        }
        $this->assertNull(CourierAccount::query()->where('courier', 'lbc')->first());
    }

    public function test_first_save_refuses_cleanly_while_locked_and_keeps_mode(): void
    {
        $lock = Cache::lock('courier-setup:spx', 10);
        $this->assertTrue($lock->get());

        $this->actingAs($this->admin())->from(route('accounts.show', 'spx'))
            ->put(route('accounts.update', 'spx'), ['mode' => 'sandbox', 'secrets' => ['spx_sandbox_secret_key' => 'typed-secret-0001']])
            ->assertRedirect(route('accounts.show', 'spx'))->assertSessionHasErrors('mode')
            ->assertSessionHasInput('mode', 'sandbox');
        $this->assertSame(0, CourierAccount::query()->count());
        $this->assertFalse(session()->hasOldInput('secrets'));
        $this->assertStringNotContainsString('typed-secret-0001', json_encode(session()->all()));
        $lock->release();
    }

    public function test_first_save_failure_leaves_no_account(): void
    {
        $this->partialMock(CourierAccounts::class, function ($m) {
            $m->shouldReceive('create')->andThrow(new CourierAccountException('nope'));
        });

        $this->actingAs($this->admin())->put(route('accounts.update', 'spx'), ['mode' => 'sandbox'])->assertSessionHasErrors('mode');
        $this->assertSame(0, CourierAccount::query()->withTrashed()->count());
        $this->assertTrue(Cache::lock('courier-setup:spx', 5)->get());
    }

    public function test_no_name_field(): void
    {
        CourierAccount::factory()->create(['courier' => 'quadx', 'label' => 'Legacy label', 'mode' => 'sandbox']);

        $html = $this->actingAs($this->admin())->get(route('accounts.show', 'quadx'))->assertOk()->getContent();
        $this->assertStringNotContainsString('name="label"', $html);
        $this->assertStringNotContainsString('>Name<', $html);
        $this->assertStringNotContainsString('Legacy label', $html);
    }

    public function test_legacy_duplicate_accounts_resolve_to_default(): void
    {
        $first = CourierAccount::factory()->create(['courier' => 'quadx', 'label' => 'First', 'status' => 'active']);
        $second = CourierAccount::factory()->create(['courier' => 'quadx', 'label' => 'Second', 'status' => 'active', 'is_default' => true, 'default_slot' => 'quadx']);
        $accounts = app(CourierAccounts::class);

        $this->assertTrue($accounts->forCourier('quadx')->is($second));
        $second->forceFill(['is_default' => false, 'default_slot' => null])->save();
        $this->assertTrue($accounts->forCourier('quadx')->is($first));
        $first->forceFill(['status' => 'inactive'])->save();
        $this->assertTrue($accounts->forCourier('quadx')->is($second));
        $second->forceFill(['status' => 'inactive'])->save();
        $this->assertTrue($accounts->forCourier('quadx')->is($first));
        $this->assertNull($accounts->forCourier('lbc'));
        $this->assertSame(2, CourierAccount::query()->where('courier', 'quadx')->count());
    }

    public function test_any_status_but_active_is_inactive(): void
    {
        $account = CourierAccount::factory()->create(['courier' => 'quadx', 'label' => 'Main', 'status' => 'disabled']);
        $this->assertFalse($account->isActive());
        $this->assertTrue($account->forceFill(['status' => 'active'])->isActive());
        $account->forceFill(['status' => 'disabled'])->save();

        $this->actingAs($this->admin())->get(route('accounts.index'))->assertOk()->assertSee('Inactive');
    }

    public function test_setup_ignores_a_trashed_account_like_for_courier(): void
    {
        CourierAccount::factory()->create(['courier' => 'quadx', 'label' => 'Old', 'status' => 'active', 'is_default' => true, 'default_slot' => 'quadx'])->delete();
        $accounts = app(CourierAccounts::class);
        $this->assertNull($accounts->forCourier('quadx'));

        $account = $accounts->setUp('quadx', 'sandbox');

        $this->assertTrue($accounts->forCourier('quadx')->is($account));
        $this->assertSame(2, CourierAccount::query()->withTrashed()->where('courier', 'quadx')->count());
    }

    public function test_create_routes_gone(): void
    {
        $this->assertFalse(Route::has('accounts.create'));
        $this->assertFalse(Route::has('accounts.store'));
        $admin = $this->admin();
        $this->actingAs($admin)->get('/accounts/new')->assertNotFound();
        $this->post('/accounts', ['courier' => 'quadx', 'label' => 'A', 'mode' => 'sandbox'])->assertStatus(405);
    }

    public function test_sidebar_shows_courier_accounts(): void
    {
        $this->actingAs($this->admin())->get(route('settings.edit'))
            ->assertSee('Courier accounts')->assertSee(route('accounts.index'), false);
    }

    public function test_cards_show_connect_button(): void
    {
        $this->actingAs($this->admin())->get(route('fulfillment'))
            ->assertSee('Connect LBC')
            ->assertSee(route('accounts.show', 'lbc'), false);
    }

    public function test_sidebar_group_label_has_readable_contrast(): void
    {
        $css = file_get_contents(resource_path('css/blotter.css'));
        preg_match_all('/--bl-side-group:\s*(#[0-9a-f]{6})/i', $css, $groups);
        preg_match_all('/--bl-side-bg:\s*(#[0-9a-f]{6})/i', $css, $bgs);
        $this->assertCount(2, $groups[1]);
        $this->assertCount(2, $bgs[1]);

        foreach ($groups[1] as $i => $fg) {
            $this->assertGreaterThanOrEqual(4.5, $this->contrast($fg, $bgs[1][$i]));
        }
    }

    private function contrast(string $a, string $b): float
    {
        $lum = function (string $hex): float {
            $c = array_map(fn ($i) => hexdec(substr(ltrim($hex, '#'), $i, 2)) / 255, [0, 2, 4]);
            $c = array_map(fn ($x) => $x <= 0.03928 ? $x / 12.92 : (($x + 0.055) / 1.055) ** 2.4, $c);

            return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
        };
        [$hi, $lo] = [max($lum($a), $lum($b)), min($lum($a), $lum($b))];

        return ($hi + 0.05) / ($lo + 0.05);
    }
}
