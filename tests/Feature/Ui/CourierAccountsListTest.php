<?php

namespace Tests\Feature\Ui;

use App\Models\Admin;
use App\Models\AuditEntry;
use App\Models\CourierAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class CourierAccountsListTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Admin
    {
        return Admin::query()->create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'correct-horse-battery']);
    }

    private function stubShow(): void
    {
        Route::middleware(['web', 'auth'])->get('/accounts/{account:public_id}', fn () => 'show')->name('accounts.show');
        Route::getRoutes()->refreshNameLookups();
    }

    public function test_list_shows_accounts_and_connect_rows(): void
    {
        CourierAccount::factory()->create(['courier' => 'quadx', 'label' => 'Main account', 'mode' => 'staging', 'is_default' => true, 'default_slot' => 'quadx', 'last_check_ok' => true]);
        CourierAccount::factory()->create(['courier' => 'spx', 'label' => 'Branch', 'mode' => 'uat', 'last_check_ok' => null]);

        $html = $this->actingAs($this->admin())->get(route('accounts.index'))->assertOk()
            ->assertSee('Courier accounts')
            ->assertSee('Connect courier')
            ->assertSee('QuadX · Main account')
            ->assertSee('Default for QuadX')
            ->assertSee('Staging')->assertSee('Connected')
            ->assertSee('SPX · Branch')->assertSee('UAT')->assertSee('Not checked')
            ->assertSee('Open')
            ->assertSee(route('accounts.create', ['courier' => 'lbc']), false)
            ->getContent();

        $this->assertStringNotContainsString('Default for SPX', $html);
        $this->assertStringNotContainsString(route('accounts.create', ['courier' => 'quadx']).'"', $html);
    }

    public function test_list_marks_failed_check_and_live_mode(): void
    {
        CourierAccount::factory()->create(['courier' => 'quadx', 'label' => 'Main', 'mode' => 'production', 'last_check_ok' => false]);

        $this->actingAs($this->admin())->get(route('accounts.index'))
            ->assertSee('Live')->assertSee('Check failed');
    }

    public function test_create_form_and_store_redirects_to_show(): void
    {
        $this->stubShow();
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('accounts.create', ['courier' => 'spx']))->assertOk()
            ->assertSee('Default for this courier')
            ->assertSeeInOrder(['value="spx"', 'selected'], false);

        $response = $this->actingAs($admin)->post(route('accounts.store'), [
            'courier' => 'quadx', 'label' => 'Main account', 'mode' => 'staging', 'default' => '1',
        ]);

        $account = CourierAccount::query()->firstOrFail();
        $response->assertRedirect(route('accounts.show', $account))->assertSessionHas('status', 'Connected');
        $this->assertTrue($account->is_default);
        $audit = AuditEntry::query()->where('action', 'courier_account.created')->firstOrFail();
        $this->assertSame('admin', $audit->actor_type);
        $this->assertSame((string) $admin->id, $audit->actor_id);
    }

    public function test_store_validation_and_unique_default(): void
    {
        $this->stubShow();
        $admin = $this->admin();

        $this->actingAs($admin)->from(route('accounts.create'))
            ->post(route('accounts.store'), ['courier' => 'nope', 'label' => '', 'mode' => 'uat'])
            ->assertRedirect(route('accounts.create'))
            ->assertSessionHasErrors(['courier', 'label', 'mode']);
        $this->assertSame(0, CourierAccount::query()->count());

        $first = $this->actingAs($admin)->post(route('accounts.store'), ['courier' => 'quadx', 'label' => 'A', 'mode' => 'staging', 'default' => '1']);
        $this->actingAs($admin)->post(route('accounts.store'), ['courier' => 'quadx', 'label' => 'B', 'mode' => 'production', 'default' => '1']);

        $this->assertSame(['B'], CourierAccount::query()->where('is_default', true)->pluck('label')->all());
        $this->assertSame(2, CourierAccount::query()->count());
    }

    public function test_store_without_show_route_goes_back_to_list(): void
    {
        if (Route::has('accounts.show')) {
            $this->markTestSkipped('accounts.show exists.');
        }

        $this->actingAs($this->admin())->post(route('accounts.store'), ['courier' => 'lbc', 'label' => 'Main', 'mode' => 'staging'])
            ->assertRedirect(route('accounts.index'))->assertSessionHas('status', 'Connected');
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
            ->assertSee(route('accounts.create', ['courier' => 'lbc']), false);
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
