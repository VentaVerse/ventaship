<?php

namespace Tests\Feature\Ui;

use App\Models\Admin;
use App\Models\ClientApp;
use App\Models\CourierAccount;
use App\Models\Shipment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class FulfillmentPageTest extends TestCase
{
    use RefreshDatabase;

    private CourierAccount $account;

    private ClientApp $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(Admin::query()->create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'correct-horse-battery']));
        $this->account = CourierAccount::factory()->create(['courier' => 'quadx', 'mode' => 'production', 'last_check_ok' => true]);
        $this->client = ClientApp::factory()->create(['name' => 'VentaCart Pro']);
    }

    private function ship(string $phase, array $over = []): Shipment
    {
        return Shipment::factory()->create(array_merge([
            'client_app_id' => $this->client->id,
            'courier_account_id' => $this->account->id,
            'phase' => $phase,
        ], $over));
    }

    public function test_page_renders_cards_tabs_and_rows(): void
    {
        $this->ship('to_handover', [
            'courier_ref' => 'VS-24-00182', 'payment_method' => 'cod', 'payment_state' => 'cod_unpaid',
            'cod_amount' => '1240.00', 'total' => '1500.00',
            'recipient' => ['first_name' => 'Maria', 'last_name' => 'Santos', 'address' => ['city' => 'Pasig City']],
            'items_count' => 2,
        ]);

        $this->get('/')->assertOk()
            ->assertSeeText(['Fulfillment', 'All', 'Unpaid', 'To ship', 'Shipping', 'Failed', 'Lost & damaged', 'Delivered', 'Returning', 'Cancelled'])
            ->assertSeeText(['QuadX', 'Live', 'Connected', 'LBC', 'No account'])
            ->assertSeeText(['VS-24-00182', 'Maria Santos', 'Pasig City', '2 items', '₱1,240.00', 'COD · Unpaid', 'To handover', 'VentaCart Pro'])
            ->assertDontSee('Connect LBC');
    }

    public function test_purged_row_renders_removed(): void
    {
        $this->ship('delivered', ['recipient' => null, 'pii_purged_at' => now()]);

        $this->get('/?tab=delivered')->assertOk()->assertSeeText('Removed');
    }

    public function test_pagination_keeps_filters(): void
    {
        Shipment::factory()->count(51)->create([
            'client_app_id' => null, 'source' => 'manual', 'phase' => 'draft', 'courier_account_id' => $this->account->id,
        ]);

        $html = $this->get('/?tab=to_ship&chip=to_pack&source=manual')->assertOk()->getContent();
        $this->assertStringContainsString('tab=to_ship&amp;chip=to_pack&amp;source=manual&amp;page=2', $html);
    }

    public function test_connect_button_hidden_without_accounts_route(): void
    {
        $this->get('/')->assertDontSee('Connect LBC');

        Route::get('/_accounts/create', fn () => 'x')->name('accounts.create');
        app('router')->getRoutes()->refreshNameLookups();
        $this->get('/')->assertSeeText('Connect LBC');
    }

    public function test_poll_returns_counts_and_phases(): void
    {
        $a = $this->ship('draft');
        $b = $this->ship('delivered');

        $this->getJson('/fulfillment/poll?ids='.$a->public_id.','.$b->public_id.',shp_nope')
            ->assertOk()
            ->assertJsonPath('phases.'.$a->public_id, 'draft')
            ->assertJsonPath('phases.'.$b->public_id, 'delivered')
            ->assertJsonMissingPath('phases.shp_nope')
            ->assertJsonPath('counts.to_ship', 1)
            ->assertJsonPath('counts.delivered', 1)
            ->assertJsonPath('counts.to_pack', 1);
    }

    public function test_poll_caps_ids_at_100(): void
    {
        $rows = Shipment::factory()->count(120)->create(['courier_account_id' => $this->account->id, 'phase' => 'draft']);

        $res = $this->getJson('/fulfillment/poll?ids='.$rows->pluck('public_id')->implode(','))->assertOk();
        $this->assertCount(100, $res->json('phases'));
    }

    public function test_guest_cannot_poll(): void
    {
        auth()->logout();
        $this->getJson('/fulfillment/poll?ids=a')->assertUnauthorized();
    }
}
