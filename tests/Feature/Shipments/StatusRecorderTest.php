<?php

namespace Tests\Feature\Shipments;

use App\Http\Resources\ShipmentResource;
use App\Models\OutgoingEvent;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Shipments\StatusRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StatusRecorderTest extends TestCase
{
    use RefreshDatabase;

    private function cod(array $attrs = []): Shipment
    {
        return Shipment::factory()->create($attrs + ['payment_method' => 'cod', 'cod_amount' => 1290, 'payment_state' => 'cod_unpaid', 'phase' => 'to_handover']);
    }

    private function recorder(): StatusRecorder
    {
        return app(StatusRecorder::class);
    }

    private function at(string $time): CarbonImmutable
    {
        return CarbonImmutable::parse("2026-11-08 {$time}", 'UTC');
    }

    public function test_new_event_updates_phase_and_writes_outbox(): void
    {
        $s = $this->cod();

        $event = $this->recorder()->record($s, 'delivered', $this->at('10:00:00'), 'webhook', 'Left with guard', ['k' => 'v']);

        $this->assertInstanceOf(ShipmentEvent::class, $event);
        $s->refresh();
        $this->assertSame('delivered', $s->phase);
        $this->assertSame('delivered', $s->courier_status);
        $this->assertSame('cod_collected', $s->payment_state);
        $this->assertTrue($s->last_event_at->eq($this->at('10:00:00')));
        $this->assertTrue($s->terminal_at->eq($this->at('10:00:00')));
        $this->assertSame(2, $s->event_sequence);

        $rows = OutgoingEvent::where('shipment_id', $s->id)->orderBy('sequence')->get();
        $this->assertSame(['shipment.status_changed', 'shipment.payment_state_changed'], $rows->pluck('type')->all());
        $this->assertSame([1, 2], $rows->pluck('sequence')->all());
        $this->assertSame('pending', $rows[0]->status);
        $this->assertSame($s->public_id, $rows[0]->payload['shipment']['id']);
        $this->assertSame('delivered', $rows[0]->payload['event']['phase']);
        $this->assertNull($rows[1]->payload['event']);
        $this->assertSame('cod_collected', $rows[1]->payload['shipment']['payment_state']);
    }

    public function test_outbox_payload_is_the_shipment_resource_without_parties(): void
    {
        $s = $this->cod(['tracking_number' => 'QX-1', 'state' => 'booked']);

        $this->recorder()->record($s, 'in_transit', $this->at('10:00:00'), 'poll');

        $payload = OutgoingEvent::where('shipment_id', $s->id)->sole()->payload;
        $expected = (new ShipmentResource($s->fresh()))->resolve();
        $this->assertSame(array_keys($expected), array_keys($payload['shipment']));
        $this->assertSame($s->courierAccount->public_id, $payload['shipment']['account']['id']);
        $this->assertSame('1290.00', $payload['shipment']['cod_amount']);
        $this->assertSame('in_transit', $payload['shipment']['phase']);
        $this->assertArrayNotHasKey('recipient', $payload['shipment']);
        $this->assertArrayNotHasKey('pickup', $payload['shipment']);
        $this->assertArrayNotHasKey('items', $payload['shipment']);
    }

    public function test_duplicate_event_is_ignored(): void
    {
        $s = $this->cod();

        $this->assertNotNull($this->recorder()->record($s, 'in_transit', $this->at('09:00:00'), 'poll'));
        $this->assertNull($this->recorder()->record($s, 'in_transit', $this->at('09:00:00'), 'webhook'));

        $this->assertSame(1, ShipmentEvent::where('shipment_id', $s->id)->count());
        $this->assertSame(1, OutgoingEvent::where('shipment_id', $s->id)->count());
        $this->assertSame(1, $s->refresh()->event_sequence);
    }

    public function test_older_event_never_moves_phase_back(): void
    {
        $s = $this->cod();

        $this->recorder()->record($s, 'delivered', $this->at('10:00:00'), 'webhook');
        $this->recorder()->record($s, 'in_transit', $this->at('09:00:00'), 'poll');

        $s->refresh();
        $this->assertSame('delivered', $s->phase);
        $this->assertSame('delivered', $s->courier_status);
        $this->assertSame('cod_collected', $s->payment_state);
        $this->assertTrue($s->last_event_at->eq($this->at('10:00:00')));
        $this->assertSame(2, ShipmentEvent::where('shipment_id', $s->id)->count());
        $this->assertSame(['delivered', 'in_transit'], ShipmentEvent::where('shipment_id', $s->id)->orderBy('id')->pluck('courier_status')->all());
        $this->assertSame(3, OutgoingEvent::where('shipment_id', $s->id)->count()); // status, payment, stale status
    }

    public function test_prepaid_stays_prepaid(): void
    {
        $s = Shipment::factory()->create(['payment_method' => 'prepaid']);

        $this->recorder()->record($s, 'delivered', $this->at('10:00:00'), 'webhook');

        $this->assertSame('prepaid', $s->refresh()->payment_state);
        $this->assertSame(['shipment.status_changed'], OutgoingEvent::where('shipment_id', $s->id)->pluck('type')->all());
    }

    public function test_cancelled_cod_becomes_void(): void
    {
        $s = $this->cod();

        $this->recorder()->record($s, 'cancelled', $this->at('10:00:00'), 'webhook');

        $s->refresh();
        $this->assertSame('cancelled', $s->phase);
        $this->assertSame('cod_void', $s->payment_state);
        $this->assertNotNull($s->terminal_at);
    }

    public function test_remitted_and_collected_never_move_back(): void
    {
        $s = $this->cod(['payment_state' => 'cod_remitted']);

        $this->recorder()->record($s, 'delivered', $this->at('10:00:00'), 'webhook');
        $this->assertSame('cod_remitted', $s->refresh()->payment_state);

        $c = $this->cod(['payment_state' => 'cod_collected']);
        $this->recorder()->record($c, 'returned', $this->at('10:00:00'), 'webhook');
        $this->assertSame('cod_collected', $c->refresh()->payment_state);
    }

    public function test_raw_is_trimmed_and_shipment_without_app_writes_no_outbox(): void
    {
        $s = $this->cod(['client_app_id' => null]);

        $event = $this->recorder()->record($s, 'in_transit', $this->at('09:00:00'), 'poll', null, ['blob' => str_repeat('é', 9000)]);

        $this->assertTrue($event->fresh()->raw['truncated']);
        $this->assertLessThanOrEqual(8192, strlen($event->fresh()->raw['excerpt']));
        $this->assertSame(0, OutgoingEvent::count());
        $this->assertSame(0, $s->refresh()->event_sequence);
        $this->assertSame('in_transit', $s->phase);
    }

    public function test_late_delivered_still_collects_payment_and_sets_terminal(): void
    {
        $s = $this->cod();

        $this->recorder()->record($s, 'in_transit', $this->at('10:00:00'), 'poll');
        $this->recorder()->record($s, 'delivered', $this->at('09:00:00'), 'webhook');

        $s->refresh();
        $this->assertSame('in_transit', $s->phase);
        $this->assertSame('in_transit', $s->courier_status);
        $this->assertTrue($s->last_event_at->eq($this->at('10:00:00')));
        $this->assertSame('cod_collected', $s->payment_state);
        $this->assertTrue($s->terminal_at->eq($this->at('09:00:00')));
        $this->assertTrue(OutgoingEvent::where('shipment_id', $s->id)->where('type', 'shipment.payment_state_changed')->exists());
    }

    public function test_unique_key_loss_returns_null_without_side_effects(): void
    {
        $s = $this->cod();
        ShipmentEvent::create(['shipment_id' => $s->id, 'source' => 'poll', 'courier_status' => 'delivered', 'phase' => 'delivered', 'occurred_at' => $this->at('10:00:00')->setTimezone(config('app.timezone'))]);

        $blind = new class(app(\App\Shipments\PhaseResolver::class)) extends StatusRecorder {
            protected function eventExists(Shipment $shipment, string $courierStatus, \Carbon\CarbonInterface $occurredAt): bool
            {
                return false;
            }
        };

        $this->assertNull($blind->record($s, 'delivered', $this->at('10:00:00'), 'webhook'));

        $s->refresh();
        $this->assertSame(0, $s->event_sequence);
        $this->assertSame(0, OutgoingEvent::count());
        $this->assertSame('cod_unpaid', $s->payment_state);
        $this->assertSame(1, ShipmentEvent::where('shipment_id', $s->id)->count());
    }
}
