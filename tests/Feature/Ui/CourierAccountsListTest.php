<?php

namespace Tests\Feature\Ui;

use App\Couriers\CourierAccounts;
use App\Models\Admin;
use App\Models\AuditEntry;
use App\Models\CourierAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        CourierAccount::factory()->create(['courier' => 'spx', 'label' => 'Branch', 'mode' => 'uat', 'last_check_ok' => null]);
        CourierAccount::factory()->create(['courier' => 'quadx', 'label' => 'Main account', 'mode' => 'staging', 'is_default' => true, 'default_slot' => 'quadx', 'last_check_ok' => true]);

        $html = $this->actingAs($this->admin())->get(route('accounts.index'))->assertOk()
            ->assertDontSee('Connect courier')
            ->assertSeeInOrder(['QuadX', 'Staging', 'Connected', 'SPX', 'UAT', 'Not checked', 'LBC', 'Set up'])
            ->assertSee(route('accounts.show', 'lbc'), false)
            ->getContent();

        $this->assertSame(3, substr_count($html, '<div class="ac-row '));
        $this->assertSame(2, substr_count($html, '>Open</a>'));
        $this->assertSame(1, substr_count($html, '>Set up</a>'));
    }

    public function test_list_marks_failed_check_and_live_mode(): void
    {
        CourierAccount::factory()->create(['courier' => 'quadx', 'label' => 'Main', 'mode' => 'production', 'last_check_ok' => false]);

        $this->actingAs($this->admin())->get(route('accounts.index'))
            ->assertSee('Live')->assertSee('Check failed');
    }

    public function test_show_without_account_offers_mode_and_set_up(): void
    {
        $this->actingAs($this->admin())->get(route('accounts.show', 'spx'))->assertOk()
            ->assertSee('SPX')->assertSee('UAT')->assertSee('Live')->assertSee('Set up')
            ->assertDontSee('Test connection')->assertDontSee('Keys');
    }

    public function test_setup_creates_default_account_named_after_courier(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('accounts.setup', 'quadx'), ['mode' => 'staging'])
            ->assertRedirect(route('accounts.show', 'quadx'))->assertSessionHas('status', 'Ready');

        $account = CourierAccount::query()->firstOrFail();
        $this->assertSame('QuadX', $account->label);
        $this->assertSame('quadx', $account->courier);
        $this->assertTrue($account->is_default);
        $audit = AuditEntry::query()->where('action', 'courier_account.created')->firstOrFail();
        $this->assertSame((string) $admin->id, $audit->actor_id);

        // A second set up is refused.
        $this->post(route('accounts.setup', 'quadx'), ['mode' => 'production'])->assertSessionHasErrors('mode');
        $this->assertSame(1, CourierAccount::query()->count());

        $this->post(route('accounts.setup', 'spx'), ['mode' => 'uat'])->assertRedirect();
        $this->assertSame('uat', CourierAccount::query()->where('courier', 'spx')->value('mode'));
        $this->post(route('accounts.setup', 'lbc'), ['mode' => 'uat'])->assertSessionHasErrors('mode');
    }

    public function test_no_name_field(): void
    {
        CourierAccount::factory()->create(['courier' => 'quadx', 'label' => 'Legacy label', 'mode' => 'staging']);

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

    public function test_create_routes_gone(): void
    {
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('accounts.create'));
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('accounts.store'));
        $admin = $this->admin();
        $this->actingAs($admin)->get('/accounts/new')->assertNotFound();
        $this->post('/accounts', ['courier' => 'quadx', 'label' => 'A', 'mode' => 'staging'])->assertStatus(405);
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
