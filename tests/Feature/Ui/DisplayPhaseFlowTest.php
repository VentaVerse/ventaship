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

    public function test_draft_is_active_with_book_and_counts_on_the_card(): void
    {
        $draft = $this->create('D1', submit: false);
        $this->assertNull($draft->refresh()->phase);
        $this->assertSame('draft', $draft->state);

        $this->assertSame(['all' => 1, 'active' => 1, 'finished' => 0], ShipmentList::counts([]));
        $this->assertSame([$draft->id], ShipmentList::query(['view' => 'active'])->pluck('id')->all());
        $this->assertSame([], ShipmentList::query(['view' => 'finished'])->pluck('id')->all());

        $card = collect(CourierCards::all())->firstWhere('courier', 'quadx');
        $this->assertSame(1, $card['active']);
        $this->assertSame('1290.00', $card['cod_unpaid']);

        $html = $this->get('/?view=active')->assertOk()->getContent();
        $row = $this->row($html, $draft);
        $this->assertStringContainsString('data-status="ventaship:draft"', $row);
        $this->assertStringNotContainsString('x-badge__label">Not booked', $row, 'Book replaces the status badge');
        $this->assertStringContainsString('form="book-'.$draft->public_id.'"', $row);
        $this->assertStringContainsString('id="book-'.$draft->public_id.'"', $html);

        $this->post('/shipments/book', ['ids' => [$draft->public_id]])
            ->assertRedirect()->assertSessionHas('status', '1 queued for booking');
        $this->assertSame('queued', $draft->refresh()->state);
        Queue::assertPushed(BookShipment::class);
    }

    public function test_queued_and_booking_rows_are_active_and_read_booking(): void
    {
        $queued = $this->create('Q1', submit: true);
        $booking = $this->create('B1', submit: true);
        app(BookingOutcomes::class)->start($booking->id);
        $this->assertSame('booking', $booking->refresh()->state);
        $this->assertNull($booking->phase);

        $this->assertEqualsCanonicalizing([$queued->id, $booking->id], ShipmentList::query(['view' => 'active', 'status' => 'ventaship:booking'])->pluck('id')->all());

        $html = $this->get('/?status=ventaship:booking')->assertOk()->getContent();
        foreach ([$queued, $booking] as $s) {
            $row = $this->row($html, $s);
            $this->assertStringContainsString('data-status="ventaship:booking"', $row);
            $this->assertStringContainsString('Booking', $row);
            $this->assertStringNotContainsString('form="book-', $row);
        }

        $this->post('/shipments/book', ['ids' => [$queued->public_id]])->assertSessionHas('status', '0 queued for booking · 1 skipped');
    }

    public function test_failed_booking_is_active_and_reads_booking_failed(): void
    {
        $s = $this->create('F1', submit: true);
        app(BookingOutcomes::class)->start($s->id);
        app(BookingOutcomes::class)->failed($s->id, 'courier_refused', 'No.');
        $this->assertSame('failed', $s->refresh()->state);
        $this->assertNull($s->phase);

        $this->assertSame([$s->id], ShipmentList::query(['view' => 'active', 'status' => 'ventaship:failed'])->pluck('id')->all());

        $row = $this->row($this->get('/?view=active')->assertOk()->getContent(), $s);
        $this->assertStringContainsString('data-status="ventaship:failed"', $row);
        $this->assertStringContainsString('Booking failed', $row);
        $this->assertStringNotContainsString('form="book-', $row);
    }

    public function test_cancelled_null_phase_is_finished_and_left_out_of_card_cod(): void
    {
        $this->create('C1', submit: false);
        $gone = $this->create('C2', submit: false);
        $gone->forceFill(['state' => 'cancelled'])->save();

        $card = collect(CourierCards::all())->firstWhere('courier', 'quadx');
        $this->assertSame('1290.00', $card['cod_unpaid']);
        $this->assertSame(1, $card['active']);
        $this->assertSame(1, ShipmentList::counts([])['finished']);
        $this->assertSame([$gone->id], ShipmentList::query(['view' => 'finished'])->pluck('id')->all());
    }

    public function test_poll_returns_status_keys_without_nulls_and_is_stable(): void
    {
        $draft = $this->create('P1', submit: false);
        $queued = $this->create('P2', submit: true);
        $ids = $draft->public_id.','.$queued->public_id;

        $first = $this->get('/')->assertOk()->getContent();
        $statuses = $this->getJson('/fulfillment/poll?ids='.$ids)->assertOk()->json('statuses');
        $this->assertSame([$draft->public_id => 'ventaship:draft', $queued->public_id => 'ventaship:booking'], $statuses);
        $this->assertNotContains(null, $statuses);

        preg_match_all('#data-id="([^"]+)" data-status="([^"]+)"#', $first, $m);
        $page = array_intersect_key(array_combine($m[1], $m[2]), $statuses);
        ksort($page);
        $sorted = $statuses;
        ksort($sorted);
        $this->assertSame($sorted, $page, 'the page and the poll agree');

        $this->get('/')->assertOk();
        $this->assertSame($statuses, $this->getJson('/fulfillment/poll?ids='.$ids)->json('statuses'));
    }
}
