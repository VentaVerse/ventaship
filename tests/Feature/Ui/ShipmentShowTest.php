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
use Tests\Concerns\BuildsShipments;
use Tests\TestCase;

class ShipmentShowTest extends TestCase
{
    use BuildsShipments, RefreshDatabase;

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

    public function test_show_lists_the_items_joined_on_one_line(): void
    {
        $s = $this->ship('booked', ['items' => [['name' => 'Shirt', 'quantity' => 2, 'price' => '10.00'], ['name' => 'Cap', 'quantity' => 1]]]);
        $this->get(route('shipments.show', $s->public_id))->assertOk()
            ->assertSeeInOrder(['Items and parcel', 'Item', '2 × Shirt, 1 × Cap', 'Weight'], false);

        $one = $this->ship('booked', ['items' => [['name' => 'Assorted goods', 'quantity' => 1]]]);
        $html = $this->get(route('shipments.show', $one->public_id))->getContent();
        $this->assertMatchesRegularExpression('~<dt>Item</dt>\s*<dd>Assorted goods</dd>~', $html);
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
        $html = $res->getContent();
        $this->assertMatchesRegularExpression('#<h2 class="sd-name is-removed">Removed</h2>#', $html);
        $this->assertMatchesRegularExpression('#<dt>Name</dt><dd class="is-removed">Removed</dd>#', $html);
    }

    public function test_actions_visibility_by_state_and_source(): void
    {
        Route::middleware(['web', 'auth'])->group(function () {
            Route::get('shipments/{shipment}/edit', fn () => 'x')->name('shipments.edit');
            Route::delete('shipments/{shipment}', fn () => 'x')->name('shipments.destroy');
        });
        app('router')->getRoutes()->refreshNameLookups();

        $has = fn ($res, string $cls) => str_contains($res->getContent(), $cls);

        $s = $this->ship('booked');
        $booked = $this->get(route('shipments.show', $s->public_id));
        $this->assertTrue($has($booked, 'sd-print') && $has($booked, 'sd-cancel'));
        $this->assertFalse($has($booked, 'sd-edit') || $has($booked, 'sd-delete') || $has($booked, 'sd-book'));
        $booked->assertSee('Cancel shipment');
        $this->assertMatchesRegularExpression('#<form id="sd-cancel"[^>]*'.preg_quote(route('shipments.cancel'), '#').'.*?name="ids\[\]" value="'.$s->public_id.'"#s', $booked->getContent());
        $this->assertMatchesRegularExpression('#<form id="sd-labels"[^>]*'.preg_quote(route('shipments.labels'), '#').'.*?name="ids\[\]" value="'.$s->public_id.'"#s', $booked->getContent());

        $failed = $this->get(route('shipments.show', $this->ship('failed', ['source' => 'manual'])->public_id));
        $this->assertTrue($has($failed, 'sd-edit') && $has($failed, 'sd-delete'));
        $this->assertFalse($has($failed, 'sd-print') || $has($failed, 'sd-book') || $has($failed, 'sd-cancel'));

        $d = $this->ship('draft', ['source' => 'api']);
        $draft = $this->get(route('shipments.show', $d->public_id));
        $this->assertTrue($has($draft, 'sd-book'));
        $this->assertFalse($has($draft, 'sd-edit') || $has($draft, 'sd-delete') || $has($draft, 'sd-print') || $has($draft, 'sd-cancel'));
        $this->assertMatchesRegularExpression('#<form id="sd-book"[^>]*'.preg_quote(route('shipments.book'), '#').'.*?name="ids\[\]" value="'.$d->public_id.'"#s', $draft->getContent());

        $manualDraft = $this->get(route('shipments.show', $this->ship('draft', ['source' => 'manual'])->public_id));
        $this->assertTrue($has($manualDraft, 'sd-book') && $has($manualDraft, 'sd-edit') && $has($manualDraft, 'sd-delete'));
    }

    public function test_non_http_tracking_url_renders_as_plain_text(): void
    {
        CourierRegistry::fake('zzz', new class extends FakeCourier
        {
            public function trackingLinks(\App\Couriers\CourierOrder $order): array
            {
                return [['url' => 'javascript:alert(1)']];
            }
        });
        $s = $this->ship('booked', ['tracking_number' => 'QX-PLAIN', 'courier' => 'zzz']);
        $html = $this->get(route('shipments.show', $s->public_id))->assertSee('QX-PLAIN')->getContent();
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('class="sd-link"', $html);
    }

    public function test_title_uses_courier_ref(): void
    {
        $s = $this->ship('draft', ['courier_ref' => 'VS-TITLE']);
        $this->get(route('shipments.show', $s->public_id))->assertSee('<title>VS-TITLE · Shipment', false);
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

    /** Detail-page Cancel appears exactly when the bulk Cancel (Canceller::request) would act on the shipment. */
    public function test_cancel_shows_for_unknown_and_spx_booking_like_the_bulk_cancel(): void
    {
        $quadx = FakeCourier::make('quadx')->throws(new \RuntimeException('timeout'));
        CourierRegistry::fake('quadx', $quadx);
        $spxAccount = CourierAccount::factory()->create(['courier' => 'spx', 'status' => 'active', 'label' => 'SPX main']);
        CourierRegistry::fake('spx', FakeCourier::make('spx')->booksAs('SPX-1', 'creating'));
        $creator = app(\App\Shipments\ShipmentCreator::class);

        $unknown = $creator->create($this->client, $this->shipmentBody(['client_reference' => 'U-1', 'account_id' => $this->account->public_id, 'courier' => null]), now: true);
        $booking = $creator->create($this->client, $this->shipmentBody(['client_reference' => 'B-1', 'account_id' => $spxAccount->public_id, 'courier' => null]), now: true);
        $this->assertSame('unknown', $unknown->state);
        $this->assertSame('booking', $booking->state);

        foreach ([$unknown, $booking] as $s) {
            $html = $this->get(route('shipments.show', $s->public_id))->assertOk()->getContent();
            $this->assertStringContainsString('sd-cancel', $html, $s->state);
            $this->assertMatchesRegularExpression('#<form id="sd-cancel"[^>]*>.*?name="ids\[\]" value="'.$s->public_id.'"#s', $html);

            // The server accepts it, and then the button is gone.
            $this->post(route('shipments.cancel'), ['ids' => [$s->public_id]]);
            $this->assertSame('requested', $s->refresh()->cancel_state);
            $this->assertStringNotContainsString('sd-cancel', $this->get(route('shipments.show', $s->public_id))->getContent());
        }
    }
}
