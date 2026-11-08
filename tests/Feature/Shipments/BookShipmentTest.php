<?php

namespace Tests\Feature\Shipments;

use App\Couriers\CourierRegistry;
use App\Couriers\FakeCourier;
use App\Couriers\LiveBookingBlocked;
use App\Models\ClientApp;
use App\Models\CourierAccount;
use App\Models\CourierCall;
use App\Models\OutgoingEvent;
use App\Models\Shipment;
use App\Shipments\Jobs\BookShipment;
use App\Shipments\Jobs\CancelAtCourier;
use App\Webhooks\Outbound\Outbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class BookShipmentTest extends TestCase
{
    use RefreshDatabase;

    private CourierAccount $account;

    private ClientApp $clientApp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->account = CourierAccount::factory()->create(['courier' => 'quadx', 'status' => 'active']);
        $this->clientApp = ClientApp::factory()->create();
    }

    public function test_job_is_configured_never_to_retry(): void
    {
        $job = new BookShipment(7);

        $this->assertSame('couriers', $job->connection);
        $this->assertSame('couriers', $job->queue);
        $this->assertSame(1, $job->tries);
        $this->assertSame(1, $job->maxExceptions);
        $this->assertSame(300, $job->timeout);
        $this->assertTrue($job->failOnTimeout);
    }

    public function test_success_books_and_emits_booked(): void
    {
        $fake = FakeCourier::make()->booksAs('QX-1', 'pending');
        CourierRegistry::fake('quadx', $fake);
        $s = $this->queued();

        Bus::dispatchSync(new BookShipment($s->id));

        $s->refresh();
        $this->assertSame('booked', $s->state);
        $this->assertSame('QX-1', $s->tracking_number);
        $this->assertSame('pending', $s->courier_status);
        $this->assertNotNull($s->booked_at);
        $this->assertNotNull($s->booking_started_at);
        $this->assertFalse($s->needs_attention);
        $this->assertTrue($s->meta['fake']);
        $this->assertSame($s->id, (int) $s->events()->sole()->getAttribute('shipment_id'));
        $this->assertSame('system', $s->events()->sole()->source);
        $this->assertCount(1, $fake->callsTo('createShipment'));

        $types = OutgoingEvent::query()->orderBy('sequence')->pluck('type')->all();
        $this->assertSame(['shipment.booked', 'shipment.status_changed'], $types);
        $booked = OutgoingEvent::query()->where('type', 'shipment.booked')->sole();
        $this->assertSame('booked', $booked->payload['shipment']['state']);
        $this->assertSame('QX-1', $booked->payload['shipment']['tracking_number']);
    }

    public function test_spx_creating_stays_booking_without_attention(): void
    {
        $fake = FakeCourier::make('spx')->booksAs('', 'creating');
        CourierRegistry::fake('spx', $fake);
        $account = CourierAccount::factory()->create(['courier' => 'spx', 'status' => 'active']);
        $s = $this->queued(['courier' => 'spx', 'courier_account_id' => $account->id]);

        Bus::dispatchSync(new BookShipment($s->id));

        $s->refresh();
        $this->assertSame('booking', $s->state);
        $this->assertSame('creating', $s->courier_status);
        $this->assertNull($s->tracking_number);
        $this->assertFalse($s->needs_attention);
        $this->assertNull($s->booked_at);
        $this->assertSame(0, OutgoingEvent::query()->count());
    }

    public function test_refusal_fails_and_frees_reference(): void
    {
        CourierRegistry::fake('quadx', FakeCourier::make()->refusesWith('Barangay not serviceable'));
        $s = $this->queued();

        Bus::dispatchSync(new BookShipment($s->id));

        $s->refresh();
        $this->assertSame('failed', $s->state);
        $this->assertNull($s->active_reference);
        $this->assertSame('courier_refused', $s->error_code);
        $this->assertSame('Barangay not serviceable', $s->error_message);
        $this->assertFalse($s->needs_attention);

        $event = OutgoingEvent::query()->sole();
        $this->assertSame('shipment.booking_failed', $event->type);
        $this->assertSame('courier_refused', $event->payload['shipment']['error']['code']);
    }

    public function test_exception_marks_unknown_and_never_retries(): void
    {
        $fake = FakeCourier::make()->throws(new ConnectionException('cURL error 28: timed out'));
        CourierRegistry::fake('quadx', $fake);
        $s = $this->queued();

        BookShipment::dispatch($s->id);
        $this->assertSame(1, DB::table('jobs')->where('queue', 'couriers')->count());

        $this->artisan('queue:work', ['connection' => 'couriers', '--queue' => 'couriers', '--once' => true, '--stop-when-empty' => true])
            ->assertExitCode(0);

        $s->refresh();
        $this->assertSame('unknown', $s->state);
        $this->assertTrue($s->needs_attention);
        $this->assertSame('Booking outcome unknown — check the courier portal before retrying', $s->attention_reason);
        $this->assertSame('4211', $s->active_reference, 'an unknown booking keeps its reference');
        $this->assertSame(0, DB::table('jobs')->count(), 'not released back onto the queue');
        $this->assertSame(0, DB::table('failed_jobs')->count(), 'handled, not rethrown');
        $this->assertCount(1, $fake->callsTo('createShipment'));
        $this->assertSame('shipment.unknown', OutgoingEvent::query()->sole()->type);
    }

    public function test_live_block_is_refusal_not_unknown(): void
    {
        CourierRegistry::fake('quadx', FakeCourier::make()->throws(new LiveBookingBlocked('lbc', 'booking', ['COURIERS_LBC_LIVE'])));
        $s = $this->queued();

        Bus::dispatchSync(new BookShipment($s->id));

        $s->refresh();
        $this->assertSame('failed', $s->state);
        $this->assertSame('live_booking_blocked', $s->error_code);
        $this->assertStringContainsString('COURIERS_LBC_LIVE', $s->error_message);
        $this->assertNull($s->active_reference);
        $this->assertFalse($s->needs_attention);
        $this->assertSame('shipment.booking_failed', OutgoingEvent::query()->sole()->type);
    }

    public function test_job_skips_when_state_changed(): void
    {
        $fake = FakeCourier::make();
        CourierRegistry::fake('quadx', $fake);
        $s = $this->queued(['state' => 'cancelled', 'active_reference' => null]);

        Bus::dispatchSync(new BookShipment($s->id));

        $this->assertSame([], $fake->calls);
        $this->assertSame('cancelled', $s->refresh()->state);
        $this->assertSame(0, OutgoingEvent::query()->count());
    }

    public function test_job_skips_a_second_run_of_the_same_shipment(): void
    {
        $fake = FakeCourier::make()->booksAs('QX-1');
        CourierRegistry::fake('quadx', $fake);
        $s = $this->queued();

        Bus::dispatchSync(new BookShipment($s->id));
        Bus::dispatchSync(new BookShipment($s->id));

        $this->assertCount(1, $fake->callsTo('createShipment'));
    }

    public function test_job_skips_while_another_worker_holds_the_lock(): void
    {
        $fake = FakeCourier::make();
        CourierRegistry::fake('quadx', $fake);
        $s = $this->queued();
        $lock = Cache::lock("shipment:{$s->id}", 600);
        $this->assertTrue($lock->get());

        Bus::dispatchSync(new BookShipment($s->id));

        $this->assertSame([], $fake->calls);
        $this->assertSame('queued', $s->refresh()->state);
        $lock->release();
    }

    public function test_cancel_requested_while_booking_dispatches_cancel_after_success(): void
    {
        Queue::fake([CancelAtCourier::class]);
        CourierRegistry::fake('quadx', FakeCourier::make()->booksAs('QX-9'));
        $s = $this->queued();
        // The client asks to cancel while the courier call is in flight (Canceller marks it requested).
        Shipment::updating(function (Shipment $row) {
            if ($row->state === 'booking' && $row->getOriginal('state') === 'queued') {
                $row->cancel_state = 'requested';
            }
        });

        Bus::dispatchSync(new BookShipment($s->id));

        $this->assertSame('booked', $s->refresh()->state);
        Queue::assertPushed(CancelAtCourier::class, fn (CancelAtCourier $job) => $job->shipmentId === $s->id);
    }

    public function test_courier_context_does_not_leak_past_the_booking(): void
    {
        CourierRegistry::fake('quadx', FakeCourier::make()->booksAs('QX-1'));
        Http::fake(['client.example/*' => Http::response('ok')]);
        $s = $this->queued();

        Bus::dispatchSync(new BookShipment($s->id));
        Http::post('https://client.example/webhook', ['a' => 1]);

        $this->assertNull(Context::get('account_id'));
        $this->assertNull(Context::get('shipment_id'));
        $this->assertSame(0, CourierCall::query()->count());
    }

    public function test_booking_with_disabled_account_is_refused_without_a_courier_call(): void
    {
        $fake = FakeCourier::make();
        CourierRegistry::fake('quadx', $fake);
        $s = $this->queued();
        $this->account->forceFill(['status' => 'disabled'])->save();

        Bus::dispatchSync(new BookShipment($s->id));

        $this->assertSame([], $fake->calls);
        $s->refresh();
        $this->assertSame('failed', $s->state);
        $this->assertSame('account_misconfigured', $s->error_code);
        $this->assertNull($s->active_reference);
    }

    public function test_failed_hook_marks_a_stuck_booking_unknown(): void
    {
        $s = $this->queued(['state' => 'booking', 'booking_started_at' => now()]);

        (new BookShipment($s->id))->failed(new RuntimeException('timed out'));

        $s->refresh();
        $this->assertSame('unknown', $s->state);
        $this->assertTrue($s->needs_attention);
        $this->assertSame('shipment.unknown', OutgoingEvent::query()->sole()->type);
    }

    public function test_outbox_renders_a_soft_deleted_account(): void
    {
        $s = $this->queued();
        $this->account->delete();

        DB::transaction(fn () => Outbox::record($this->clientApp, 'shipment.unknown', ['event' => null], Shipment::query()->findOrFail($s->id)));

        $payload = OutgoingEvent::query()->sole()->payload;
        $this->assertSame($this->account->public_id, $payload['shipment']['account']['id']);
    }

    private function queued(array $attributes = []): Shipment
    {
        return Shipment::factory()->create($attributes + [
            'client_app_id' => $this->clientApp->id,
            'courier_account_id' => $this->account->id,
            'client_reference' => '4211',
            'active_reference' => '4211',
            'courier_ref' => 'VS000001',
            'state' => 'queued',
        ]);
    }
}
