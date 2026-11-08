<?php

namespace Tests\Feature\Ui;

use App\Couriers\CourierRegistry;
use App\Couriers\FakeCourier;
use App\Models\Admin;
use App\Models\ClientApp;
use App\Models\CourierAccount;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ShipmentShowTest extends TestCase
{
    use RefreshDatabase;

    private CourierAccount $account;

    private ClientApp $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(Admin::query()->create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'correct-horse-battery']));
        $this->account = CourierAccount::factory()->create(['courier' => 'quadx', 'status' => 'active', 'label' => 'Main']);
        $this->client = ClientApp::factory()->create(['name' => 'VentaCart']);
        CourierRegistry::fake('quadx', FakeCourier::make('quadx'));
    }

    private function ship(string $state, array $over = []): Shipment
    {
        return Shipment::factory()->create($over + [
            'client_app_id' => $this->client->id,
            'courier_account_id' => $this->account->id,
            'state' => $state,
            'tracking_number' => in_array($state, ['booked', 'cancelled'], true) ? 'QX-'.uniqid() : null,
            'courier_ref' => 'VS-'.uniqid(),
            'recipient' => ['first_name' => 'Maria', 'last_name' => 'Cruz', 'phone' => '09171234567', 'address_1' => '12 Rizal St', 'city' => 'Makati', 'state' => 'Metro Manila', 'postcode' => '1200'],
        ]);
    }

    public function test_show_renders_sections_and_timeline_newest_first(): void
    {
        $s = $this->ship('booked', [
            'payment_method' => 'cod', 'payment_state' => 'cod_unpaid', 'cod_amount' => '1240.50', 'declared_value' => '900.00',
            'options' => ['insure' => true, 'parcel' => ['weight_kg' => 1.5]],
            'booked_at' => '2026-11-08 14:35:00',
            'needs_attention' => true, 'attention_reason' => 'Address unclear',
        ]);
        ShipmentEvent::query()->create(['shipment_id' => $s->id, 'source' => 'poll', 'courier_status' => 'picked_up', 'label' => 'Picked up zzz', 'phase' => 'in_transit', 'occurred_at' => '2026-11-08 13:00:00']);
        ShipmentEvent::query()->create(['shipment_id' => $s->id, 'source' => 'poll', 'courier_status' => 'out_for_delivery', 'label' => 'Out for delivery yyy', 'phase' => 'in_transit', 'occurred_at' => '2026-11-08 17:00:00']);

        $res = $this->get(route('shipments.show', $s->public_id))->assertOk();
        $res->assertSeeInOrder(['Customer', 'Items and parcel', 'Payment', 'Courier', 'Source', 'Timeline']);
        $res->assertSee('Maria Cruz')->assertSee('12 Rizal St')->assertSee('09171234567')
            ->assertSee($s->courier_ref)->assertSee($s->client_reference)
            ->assertSee('Blue Tee')->assertSee('₱1,240.50')->assertSee('₱900.00')
            ->assertSee('COD · Unpaid')->assertSee('Address unclear')
            ->assertSee('VentaCart')->assertSee($s->tracking_number)
            ->assertSee('Nov 8, 2026 · 2:35 PM', false);
        $res->assertSeeInOrder(['Out for delivery yyy', 'Picked up zzz']);
        $res->assertSee('Nov 8, 2026 · 1:00 PM', false);
    }

    public function test_status_flash_renders(): void
    {
        $s = $this->ship('booked');
        $this->withSession(['status' => 'Booked · QX-1'])->get(route('shipments.show', $s->public_id))->assertSee('Booked · QX-1');
    }

    public function test_purged_shipment_shows_removed(): void
    {
        $s = $this->ship('cancelled', ['recipient' => null, 'pii_purged_at' => now()]);
        $res = $this->get(route('shipments.show', $s->public_id))->assertOk();
        $this->assertSame(2, substr_count($res->getContent(), 'Removed'));
    }

    public function test_actions_visibility_by_state_and_source(): void
    {
        Route::middleware(['web', 'auth'])->group(function () {
            Route::get('shipments/{shipment}/edit', fn () => 'x')->name('shipments.edit');
            Route::delete('shipments/{shipment}', fn () => 'x')->name('shipments.destroy');
        });
        app('router')->getRoutes()->refreshNameLookups();

        $booked = $this->get(route('shipments.show', $this->ship('booked')->public_id));
        $booked->assertSee('Print waybill')->assertSee('Cancel shipment')->assertDontSee('Edit')->assertDontSee('>Book<', false)->assertDontSee('Delete');

        $failed = $this->get(route('shipments.show', $this->ship('failed', ['source' => 'manual'])->public_id));
        $failed->assertSee('Edit')->assertSee('Delete')->assertDontSee('Print waybill')->assertDontSee('>Book<', false);

        $draft = $this->get(route('shipments.show', $this->ship('draft', ['source' => 'api'])->public_id));
        $draft->assertSee('>Book<', false)->assertDontSee('Edit')->assertDontSee('Delete')->assertDontSee('Print waybill');

        $manualDraft = $this->get(route('shipments.show', $this->ship('draft', ['source' => 'manual'])->public_id));
        $manualDraft->assertSee('>Book<', false)->assertSee('Edit')->assertSee('Delete');
    }

    public function test_unknown_id_404(): void
    {
        $this->get('/shipments/shp_nope')->assertNotFound();
    }

    public function test_row_links_to_show(): void
    {
        $s = $this->ship('draft');
        $this->get(route('fulfillment'))->assertSee('href="'.route('shipments.show', $s->public_id).'"', false);
    }
}
