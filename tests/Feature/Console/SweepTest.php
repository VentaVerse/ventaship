<?php

namespace Tests\Feature\Console;

use App\Models\ClientApp;
use App\Models\OutgoingEvent;
use App\Models\Shipment;
use App\Shipments\BookingOutcomes;
use App\Shipments\Jobs\CancelAtCourier;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SweepTest extends TestCase
{
    use RefreshDatabase;

    private ClientApp $clientApp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clientApp = ClientApp::factory()->create();
    }

    public function test_stale_queued_fails(): void
    {
        $stale = $this->shipment(['state' => 'queued'], minutesAgo: 31);
        $fresh = $this->shipment(['state' => 'queued'], minutesAgo: 29);

        $this->artisan('ventaship:shipments-sweep')->assertExitCode(0);

        $stale->refresh();
        $this->assertSame('failed', $stale->state);
        $this->assertSame('not_started', $stale->error_code);
        $this->assertNull($stale->active_reference);
        $this->assertSame('queued', $fresh->refresh()->state);
        $event = OutgoingEvent::query()->sole();
        $this->assertSame('shipment.booking_failed', $event->type);
        $this->assertSame($stale->id, (int) $event->shipment_id);
    }

    public function test_stale_booking_becomes_unknown(): void
    {
        $stale = $this->shipment(['state' => 'booking', 'booking_started_at' => now()->subMinutes(11)]);
        $fresh = $this->shipment(['state' => 'booking', 'booking_started_at' => now()->subMinutes(9)]);
        $creating = $this->shipment([
            'state' => 'booking', 'courier' => 'spx', 'courier_status' => 'creating',
            'booking_started_at' => now()->subHour(),
        ]);

        $this->artisan('ventaship:shipments-sweep')->assertExitCode(0);

        $stale->refresh();
        $this->assertSame('unknown', $stale->state);
        $this->assertTrue($stale->needs_attention);
        $this->assertSame('4211-'.$stale->id, $stale->active_reference, 'an unknown booking keeps its reference');
        $this->assertSame('booking', $fresh->refresh()->state);
        $this->assertSame('booking', $creating->refresh()->state);
        $this->assertSame('shipment.unknown', OutgoingEvent::query()->sole()->type);
    }

    public function test_spx_creating_for_more_than_six_hours_is_flagged_without_a_courier_call(): void
    {
        Queue::fake();
        $attributes = ['state' => 'booking', 'courier' => 'spx', 'courier_status' => 'creating'];
        $old = $this->shipment($attributes + ['booking_started_at' => now()->subHours(6)->subMinute()]);
        $young = $this->shipment($attributes + ['booking_started_at' => now()->subHours(6)->addMinute()]);
        $other = $this->shipment($attributes + [
            'booking_started_at' => now()->subHours(7), 'needs_attention' => true, 'attention_reason' => 'Something else',
        ]);

        $this->artisan('ventaship:shipments-sweep')->assertExitCode(0);
        $this->artisan('ventaship:shipments-sweep')->assertExitCode(0);

        $old->refresh();
        $this->assertSame('booking', $old->state);
        $this->assertSame('creating', $old->courier_status);
        $this->assertTrue($old->needs_attention);
        $this->assertSame('SPX has not confirmed the booking after 6 hours. Check the SPX portal', $old->attention_reason);
        $this->assertSame('4211-'.$old->id, $old->active_reference);
        $this->assertFalse($young->refresh()->needs_attention);
        $this->assertSame('Something else', $other->refresh()->attention_reason);
        $this->assertSame(0, OutgoingEvent::query()->count());
        Queue::assertNothingPushed();

        // SPX confirming it later clears that flag.
        $this->assertTrue(app(BookingOutcomes::class)->creationResolved($old->id, 'SPXPH000001'));
        $old->refresh();
        $this->assertSame('booked', $old->state);
        $this->assertFalse($old->needs_attention);
        $this->assertNull($old->attention_reason);
        $this->assertSame('Something else', $other->refresh()->attention_reason);
    }

    public function test_booking_still_locked_by_its_job_is_left_alone(): void
    {
        $s = $this->shipment(['state' => 'booking', 'booking_started_at' => now()->subMinutes(11)]);
        $lock = Cache::lock("shipment:{$s->id}", 600);
        $lock->get();

        $this->artisan('ventaship:shipments-sweep')->assertExitCode(0);

        $this->assertSame('booking', $s->refresh()->state);
        $lock->release();
    }

    public function test_sweep_is_scheduled_every_minute(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'ventaship:shipments-sweep'));

        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
    }

    public function test_cancel_stuck_in_requested_is_flagged_without_a_courier_call(): void
    {
        Queue::fake();
        $attributes = ['state' => 'booked', 'tracking_number' => 'QX-1', 'cancel_state' => 'requested', 'booked_at' => now()->subHours(2)];
        $stuck = $this->shipment($attributes + ['cancel_requested_at' => now()->subMinutes(31)]);
        $fresh = $this->shipment($attributes + ['cancel_requested_at' => now()->subMinutes(29)]);
        $justBooked = $this->shipment(array_replace($attributes, ['booked_at' => now()->subMinutes(5)]) + ['cancel_requested_at' => now()->subHour()]);
        $running = $this->shipment($attributes + ['cancel_requested_at' => now()->subHour()]);
        $flagged = $this->shipment($attributes + ['cancel_requested_at' => now()->subHour(), 'needs_attention' => true, 'attention_reason' => 'Something else']);
        $lock = Cache::lock("shipment-cancel:{$running->id}", 300);
        $lock->get();

        $this->artisan('ventaship:shipments-sweep')->assertExitCode(0);
        $this->artisan('ventaship:shipments-sweep')->assertExitCode(0);

        $stuck->refresh();
        $this->assertTrue($stuck->needs_attention);
        $this->assertSame('Cancel request stuck. Check and retry', $stuck->attention_reason);
        $this->assertSame('requested', $stuck->cancel_state);
        foreach ([$fresh, $justBooked, $running] as $s) {
            $this->assertFalse($s->refresh()->needs_attention);
        }
        $this->assertSame('Something else', $flagged->refresh()->attention_reason);
        $this->assertSame(0, OutgoingEvent::query()->count());
        Queue::assertNothingPushed();
        $lock->release();
    }

    public function test_resolve_not_booked_fails_an_unknown_shipment(): void
    {
        $s = $this->unknown();

        $this->artisan('ventaship:shipments-resolve', ['shipment' => $s->public_id, '--not-booked' => true])
            ->assertExitCode(0);

        $s->refresh();
        $this->assertSame('failed', $s->state);
        $this->assertSame('not_booked', $s->error_code);
        $this->assertNull($s->active_reference);
        $this->assertFalse($s->needs_attention);
        $this->assertNull($s->attention_reason);
        $this->assertSame('shipment.resolved', OutgoingEvent::query()->sole()->type);
    }

    public function test_resolve_with_tracking_books_an_unknown_shipment(): void
    {
        Queue::fake([CancelAtCourier::class]);
        $s = $this->unknown();

        $this->artisan('ventaship:shipments-resolve', ['shipment' => $s->public_id, '--tracking' => 'QX-777'])
            ->assertExitCode(0);

        $s->refresh();
        $this->assertSame('booked', $s->state);
        $this->assertSame('QX-777', $s->tracking_number);
        $this->assertNotNull($s->booked_at);
        $this->assertFalse($s->needs_attention);
        $this->assertSame('4211-'.$s->id, $s->active_reference);
        $this->assertSame('shipment.resolved', OutgoingEvent::query()->sole()->type);
        Queue::assertNotPushed(CancelAtCourier::class);
    }

    public function test_resolve_refuses_bad_input_and_non_unknown_shipments(): void
    {
        $unknown = $this->unknown();
        $booked = $this->shipment(['state' => 'booked', 'tracking_number' => 'QX-1']);

        $this->artisan('ventaship:shipments-resolve', ['shipment' => $unknown->public_id])->assertExitCode(1);
        $this->artisan('ventaship:shipments-resolve', ['shipment' => $unknown->public_id, '--not-booked' => true, '--tracking' => 'QX-2'])->assertExitCode(1);
        $this->artisan('ventaship:shipments-resolve', ['shipment' => $unknown->public_id, '--tracking' => 'bad tracking!'])->assertExitCode(1);
        $this->artisan('ventaship:shipments-resolve', ['shipment' => $booked->public_id, '--not-booked' => true])->assertExitCode(1);
        $this->artisan('ventaship:shipments-resolve', ['shipment' => 'shp_nope', '--not-booked' => true])->assertExitCode(1);

        $this->assertSame('unknown', $unknown->refresh()->state);
        $this->assertSame('booked', $booked->refresh()->state);
        $this->assertSame(0, OutgoingEvent::query()->count());
    }

    private function unknown(): Shipment
    {
        return $this->shipment([
            'state' => 'unknown', 'needs_attention' => true,
            'attention_reason' => 'Booking outcome unknown. Check the courier portal before retrying',
        ]);
    }

    private function shipment(array $attributes, int $minutesAgo = 0): Shipment
    {
        $s = Shipment::factory()->create($attributes + ['client_app_id' => $this->clientApp->id]);
        $s->forceFill(['active_reference' => '4211-'.$s->id, 'client_reference' => '4211-'.$s->id])->saveQuietly();

        if ($minutesAgo > 0) {
            Shipment::query()->whereKey($s->id)->toBase()->update([
                'created_at' => now()->subMinutes($minutesAgo),
                'updated_at' => now()->subMinutes($minutesAgo),
            ]);
        }

        return $s->refresh();
    }
}
