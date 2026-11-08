<?php

namespace Tests\Feature\Shipments;

use App\Couriers\Contracts\ThrottlesStatusPolling;
use App\Couriers\CourierRegistry;
use App\Couriers\FakeCourier;
use App\Models\ClientApp;
use App\Models\CourierAccount;
use App\Models\OutgoingEvent;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Shipments\Jobs\CancelAtCourier;
use App\Shipments\Poller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PollerTest extends TestCase
{
    use RefreshDatabase;

    private ClientApp $clientApp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clientApp = ClientApp::factory()->create();
    }

    public function test_polls_non_terminal_only_and_throttles_lbc(): void
    {
        $quadx = FakeCourier::make('quadx')->tracks([['status' => 'in_transit', 'at' => now()->subMinutes(5)->getTimestamp()]]);
        CourierRegistry::fake('quadx', $quadx);
        $lbc = $this->throttledLbc(60)->tracks([['status' => 'picked_up', 'at' => now()->subMinutes(5)->getTimestamp()]]);
        CourierRegistry::fake('lbc', $lbc);

        $qxAccount = CourierAccount::factory()->create(['courier' => 'quadx']);
        $lbcAccount = CourierAccount::factory()->create(['courier' => 'lbc']);

        $live = $this->booked($qxAccount, 'QX-LIVE');
        $delivered = $this->booked($qxAccount, 'QX-DONE', ['courier_status' => 'delivered', 'terminal_at' => now()->subDay()]);
        $notDue = $this->booked($qxAccount, 'QX-LATER', ['next_poll_at' => now()->addMinutes(5)]);
        $cancelled = $this->booked($qxAccount, 'QX-CANCELLED', ['state' => 'cancelled']);
        $lbcRecent = $this->booked($lbcAccount, '1000001', ['last_polled_at' => now()->subMinutes(20)]);
        $lbcStale = $this->booked($lbcAccount, '1000002', ['last_polled_at' => now()->subMinutes(61)]);

        $polled = app(Poller::class)->run();

        $this->assertSame(2, $polled);
        $this->assertSame(['QX-LIVE'], array_map(fn ($c) => $c['args'][0], $quadx->callsTo('getTracking')));
        $this->assertSame(['1000002'], array_map(fn ($c) => $c['args'][0], $lbc->callsTo('getTracking')));

        $event = ShipmentEvent::query()->where('shipment_id', $live->id)->sole();
        $this->assertSame('poll', $event->source);
        $this->assertSame('in_transit', $live->refresh()->courier_status);
        $this->assertNotNull($live->last_polled_at);
        $this->assertNull($live->next_poll_at, 'a courier without throttling is asked again next run');

        $lbcStale->refresh();
        $this->assertSame('poll', ShipmentEvent::query()->where('shipment_id', $lbcStale->id)->sole()->source);
        $this->assertEqualsWithDelta(now()->addMinutes(60)->getTimestamp(), $lbcStale->next_poll_at->getTimestamp(), 5);

        foreach ([$delivered, $notDue, $cancelled, $lbcRecent] as $untouched) {
            $this->assertSame(0, ShipmentEvent::query()->where('shipment_id', $untouched->id)->count());
        }
    }

    public function test_polling_does_not_touch_updated_at(): void
    {
        CourierRegistry::fake('quadx', FakeCourier::make('quadx'));
        $s = $this->booked(CourierAccount::factory()->create(['courier' => 'quadx']), 'QX-1', ['courier_status' => 'pending']);
        Shipment::query()->whereKey($s->id)->toBase()->update(['updated_at' => now()->subHour()]);

        app(Poller::class)->run();

        $s->refresh();
        $this->assertNotNull($s->last_polled_at);
        $this->assertTrue($s->updated_at->lessThan(now()->subMinutes(59)));
    }

    public function test_batches_rotate_so_shipments_past_the_cap_are_polled(): void
    {
        $fake = FakeCourier::make('quadx');
        CourierRegistry::fake('quadx', $fake);
        $account = CourierAccount::factory()->create(['courier' => 'quadx']);
        foreach (['QX-1', 'QX-2', 'QX-3'] as $tn) {
            $this->booked($account, $tn, ['courier_status' => 'pending']);
        }
        $poller = app(Poller::class);
        $poller->perAccount = 2;

        $this->assertSame(2, $poller->run());
        $this->travel(1)->minutes();
        $this->assertSame(2, $poller->run());

        $asked = array_map(fn ($c) => $c['args'][0], $fake->callsTo('getTracking'));
        $this->assertSame(['QX-1', 'QX-2', 'QX-3', 'QX-1'], $asked);
    }

    public function test_an_account_locked_by_another_poll_is_skipped(): void
    {
        $fake = FakeCourier::make('quadx');
        CourierRegistry::fake('quadx', $fake);
        $account = CourierAccount::factory()->create(['courier' => 'quadx']);
        $this->booked($account, 'QX-1');
        $lock = Cache::lock("poll:{$account->id}", 600);
        $lock->get();

        $this->assertSame(0, app(Poller::class)->run());
        $this->assertSame([], $fake->callsTo('getTracking'));
        $lock->release();
    }

    public function test_a_courier_error_does_not_stop_the_other_shipments(): void
    {
        $fake = FakeCourier::make('quadx')->throws(new \RuntimeException('boom'), 'getTracking');
        CourierRegistry::fake('quadx', $fake);
        $account = CourierAccount::factory()->create(['courier' => 'quadx']);
        $a = $this->booked($account, 'QX-1');
        $b = $this->booked($account, 'QX-2');

        app(Poller::class)->run();

        $this->assertCount(2, $fake->callsTo('getTracking'));
        $this->assertNotNull($a->refresh()->last_polled_at);
        $this->assertNotNull($b->refresh()->last_polled_at);
    }

    public function test_spx_pending_creation_resolved_to_booked_goes_through_booking_outcomes(): void
    {
        Queue::fake([CancelAtCourier::class]);
        $fake = FakeCourier::make('spx')->resolvesPendingAs(['tracking_number' => 'SPEPH-NEW']);
        CourierRegistry::fake('spx', $fake);
        $account = CourierAccount::factory()->create(['courier' => 'spx']);
        $s = $this->creating($account, ['cancel_state' => 'requested']);

        app(Poller::class)->run();

        $s->refresh();
        $this->assertSame('booked', $s->state);
        $this->assertSame('SPEPH-NEW', $s->tracking_number);
        $this->assertNotNull($s->booked_at);
        $this->assertCount(1, $fake->callsTo('resolvePendingCreation'));
        // Booked, then asked for its first status in the same run: `creating` never survives the booking.
        $this->assertSame(['SPEPH-NEW'], array_map(fn ($c) => $c['args'][0], $fake->callsTo('getTracking')));
        $this->assertSame('pending', $s->courier_status);
        $this->assertSame(['shipment.booked', 'shipment.status_changed'], OutgoingEvent::query()->orderBy('id')->pluck('type')->all());
        Queue::assertPushed(CancelAtCourier::class, fn ($job) => $job->shipmentId === $s->id);
    }

    public function test_creation_resolved_twice_books_once_without_attention(): void
    {
        $s = $this->creating(CourierAccount::factory()->create(['courier' => 'spx']));
        $outcomes = app(\App\Shipments\BookingOutcomes::class);

        $this->assertTrue($outcomes->creationResolved($s->id, 'SPEPH-1'));
        $this->assertFalse($outcomes->creationResolved($s->id, 'SPEPH-1'), 'a webhook and the poller racing book it once');

        $s->refresh();
        $this->assertSame('booked', $s->state);
        $this->assertNull($s->courier_status);
        $this->assertFalse($s->needs_attention);
        $this->assertArrayNotHasKey('pending', $s->meta);
        $this->assertSame(1, OutgoingEvent::query()->where('type', 'shipment.booked')->count());
    }

    public function test_spx_pending_creation_refused_fails_and_settles_a_requested_cancel(): void
    {
        CourierRegistry::fake('spx', FakeCourier::make('spx')->resolvesPendingAs([
            'courier_status' => 'creation_failed',
            'meta' => ['creation_failed' => true, 'creation_error' => 'Address not serviceable'],
        ]));
        $account = CourierAccount::factory()->create(['courier' => 'spx']);
        $s = $this->creating($account, ['cancel_state' => 'requested']);

        app(Poller::class)->run();

        $s->refresh();
        $this->assertSame('failed', $s->state);
        $this->assertSame('courier_refused', $s->error_code);
        $this->assertStringContainsString('Address not serviceable', $s->error_message);
        $this->assertSame('cancelled', $s->cancel_state);
        $this->assertNull($s->active_reference);
        $this->assertSame(['shipment.booking_failed', 'shipment.cancelled'], OutgoingEvent::query()->orderBy('id')->pluck('type')->all());
    }

    public function test_spx_pending_creation_still_pending_stays_creating(): void
    {
        $fake = FakeCourier::make('spx');
        CourierRegistry::fake('spx', $fake);
        $s = $this->creating(CourierAccount::factory()->create(['courier' => 'spx']));

        app(Poller::class)->run();

        $s->refresh();
        $this->assertSame('booking', $s->state);
        $this->assertSame('creating', $s->courier_status);
        $this->assertNotNull($s->last_polled_at);
        $this->assertSame([], $fake->callsTo('getTracking'));
    }

    public function test_couriers_poll_command_runs_the_poller(): void
    {
        $fake = FakeCourier::make('quadx');
        CourierRegistry::fake('quadx', $fake);
        $this->booked(CourierAccount::factory()->create(['courier' => 'quadx']), 'QX-1');

        $this->artisan('ventaship:couriers-poll')->assertExitCode(0);

        $this->assertCount(1, $fake->callsTo('getTracking'));
    }

    private function throttledLbc(int $minutes): FakeCourier
    {
        $class = new class extends FakeCourier implements ThrottlesStatusPolling
        {
            public static int $minutes = 60;

            public function pollIntervalMinutes(?string $status): int
            {
                return self::$minutes;
            }
        };
        $class::$minutes = $minutes;

        return $class::make('lbc');
    }

    private function booked(CourierAccount $account, string $tracking, array $attributes = []): Shipment
    {
        return Shipment::factory()->create($attributes + [
            'client_app_id' => $this->clientApp->id,
            'courier_account_id' => $account->id,
            'courier' => $account->courier,
            'state' => 'booked',
            'tracking_number' => $tracking,
            'booked_at' => now()->subHours(2),
        ]);
    }

    private function creating(CourierAccount $account, array $attributes = []): Shipment
    {
        $s = Shipment::factory()->create($attributes + [
            'client_app_id' => $this->clientApp->id,
            'courier_account_id' => $account->id,
            'courier' => $account->courier,
            'state' => 'booking',
            'courier_status' => 'creating',
            'courier_ref' => 'VS000099',
            'tracking_number' => null,
            'booking_started_at' => now()->subMinutes(20),
            'meta' => ['batch_no' => 123, 'pending' => true],
        ]);
        $s->forceFill(['active_reference' => $s->client_reference])->saveQuietly();

        return $s;
    }
}
