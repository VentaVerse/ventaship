<?php

namespace Tests\Feature\Ui;

use App\Fulfillment\CourierCards;
use App\Fulfillment\DisplayPhase;
use App\Fulfillment\ShipmentList;
use App\Models\Admin;
use App\Models\ClientApp;
use App\Models\Shipment;
use App\Shipments\BookingOutcomes;
use App\Shipments\Jobs\BookShipment;
use App\Shipments\ShipmentCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsShipments;
use Tests\TestCase;

/** Shipments made the way production makes them: `phase` stays null until the courier reports a status. */
class DisplayPhaseFlowTest extends TestCase
{
    use BuildsShipments, RefreshDatabase;

    private ClientApp $client;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->actingAs(Admin::query()->create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'correct-horse-battery']));
        $this->fakeDefaultAccount();
        $this->client = ClientApp::factory()->create(['name' => 'VentaCart Pro']);
    }

    private function create(string $ref, bool $submit): Shipment
    {
        return app(ShipmentCreator::class)->create($this->client, $this->shipmentBody(['client_reference' => $ref, 'submit' => $submit]));
    }

    private function row(string $html, Shipment $s): string
    {
        $this->assertSame(1, preg_match('#<div class="fl-row" data-id="'.preg_quote($s->public_id, '#').'".*?(?=<div class="fl-row" data-id=|</form>)#s', $html, $m), 'row rendered');

        return $m[0];
    }

    public function test_display_phase_maps_states_when_phase_is_null(): void
    {
        $s = new Shipment(['phase' => null]);
        foreach (['draft' => 'draft', 'queued' => 'booking', 'booking' => 'booking', 'booked' => 'to_handover',
            'failed' => 'attention', 'unknown' => 'attention', 'cancelled' => 'cancelled', 'weird' => 'attention'] as $state => $phase) {
            $s->state = $state;
            $this->assertSame($phase, DisplayPhase::of($s), $state);
        }
        $s->phase = 'in_transit';
        $this->assertSame('in_transit', DisplayPhase::of($s));
    }

    public function test_draft_shows_in_to_pack_with_book_and_counts_on_the_card(): void
    {
        $draft = $this->create('D1', submit: false);
        $this->assertNull($draft->refresh()->phase);
        $this->assertSame('draft', $draft->state);

        $counts = ShipmentList::counts([]);
        $this->assertSame(1, $counts['all']);
        $this->assertSame(1, $counts['to_ship']);
        $this->assertSame(1, $counts['to_pack']);
        $this->assertSame(0, $counts['to_handover']);
        $this->assertSame([$draft->id], ShipmentList::query(['tab' => 'to_ship', 'chip' => 'to_pack'])->pluck('id')->all());
        $this->assertSame([$draft->id], ShipmentList::query(['tab' => 'all'])->pluck('id')->all());

        $card = collect(CourierCards::all())->firstWhere('courier', 'quadx');
        $this->assertSame(1, $card['to_ship']);
        $this->assertSame('1290.00', $card['cod_unpaid']);

        $html = $this->get('/?tab=to_ship&chip=to_pack')->assertOk()->getContent();
        $row = $this->row($html, $draft);
        $this->assertStringContainsString('data-phase="draft"', $row);
        $this->assertStringContainsString('form="book-'.$draft->public_id.'"', $row);
        $this->assertStringContainsString('id="book-'.$draft->public_id.'"', $html);

        $this->post('/shipments/book', ['ids' => [$draft->public_id]])
            ->assertRedirect()->assertSessionHas('status', '1 queued for booking');
        $this->assertSame('queued', $draft->refresh()->state);
        Queue::assertPushed(BookShipment::class);
    }

    public function test_queued_and_booking_rows_show_in_to_handover_as_booking(): void
    {
        $queued = $this->create('Q1', submit: true);
        $booking = $this->create('B1', submit: true);
        app(BookingOutcomes::class)->start($booking->id);
        $this->assertSame('booking', $booking->refresh()->state);
        $this->assertNull($booking->phase);

        $this->assertSame(2, ShipmentList::counts([])['to_handover']);
        $this->assertEqualsCanonicalizing([$queued->id, $booking->id], ShipmentList::query(['tab' => 'to_ship', 'chip' => 'to_handover'])->pluck('id')->all());

        $html = $this->get('/?tab=to_ship&chip=to_handover')->assertOk()->getContent();
        foreach ([$queued, $booking] as $s) {
            $row = $this->row($html, $s);
            $this->assertStringContainsString('data-phase="booking"', $row);
            $this->assertStringContainsString('Booking', $row);
            $this->assertStringNotContainsString('form="book-', $row);
        }

        $this->post('/shipments/book', ['ids' => [$queued->public_id]])->assertSessionHas('status', '0 queued for booking · 1 skipped');
    }

    public function test_failed_booking_shows_in_to_pack_as_needs_attention(): void
    {
        $s = $this->create('F1', submit: true);
        app(BookingOutcomes::class)->start($s->id);
        app(BookingOutcomes::class)->failed($s->id, 'courier_refused', 'No.');
        $this->assertSame('failed', $s->refresh()->state);
        $this->assertNull($s->phase);

        $this->assertSame([$s->id], ShipmentList::query(['tab' => 'to_ship', 'chip' => 'to_pack'])->pluck('id')->all());
        $this->assertSame(0, ShipmentList::counts([])['failed'], 'the Failed tab is the courier delivery failure');

        $row = $this->row($this->get('/?tab=to_ship&chip=to_pack')->assertOk()->getContent(), $s);
        $this->assertStringContainsString('data-phase="attention"', $row);
        $this->assertStringContainsString('Needs attention', $row);
        $this->assertStringNotContainsString('form="book-', $row);
    }

    public function test_cancelled_null_phase_is_left_out_of_card_cod(): void
    {
        $this->create('C1', submit: false);
        $gone = $this->create('C2', submit: false);
        $gone->forceFill(['state' => 'cancelled'])->save();

        $card = collect(CourierCards::all())->firstWhere('courier', 'quadx');
        $this->assertSame('1290.00', $card['cod_unpaid']);
        $this->assertSame(1, ShipmentList::counts([])['cancelled']);
    }

    public function test_poll_returns_display_phases_without_nulls_and_is_stable(): void
    {
        $draft = $this->create('P1', submit: false);
        $queued = $this->create('P2', submit: true);
        $ids = $draft->public_id.','.$queued->public_id;

        $first = $this->get('/')->assertOk()->getContent();
        $phases = $this->getJson('/fulfillment/poll?ids='.$ids)->assertOk()->json('phases');
        $this->assertSame([$draft->public_id => 'draft', $queued->public_id => 'booking'], $phases);
        $this->assertNotContains(null, $phases);

        preg_match_all('#data-id="([^"]+)" data-phase="([^"]+)"#', $first, $m);
        $page = array_intersect_key(array_combine($m[1], $m[2]), $phases);
        ksort($page);
        $sorted = $phases;
        ksort($sorted);
        $this->assertSame($sorted, $page, 'the page and the poll agree');

        $this->get('/')->assertOk();
        $this->assertSame($phases, $this->getJson('/fulfillment/poll?ids='.$ids)->json('phases'));
    }
}
