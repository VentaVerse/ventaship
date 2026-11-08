<?php

namespace Tests\Feature\Shipments;

use App\Api\ApiError;
use App\Couriers\CourierRegistry;
use App\Couriers\FakeCourier;
use App\Models\ClientApp;
use App\Models\Shipment;
use App\Shipments\Canceller;
use App\Shipments\Jobs\BookShipment;
use App\Shipments\Jobs\CancelAtCourier;
use App\Shipments\ShipmentCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Concerns\BuildsShipments;
use Tests\TestCase;

/** UI requests reach the courier during the request: no queue worker runs on the install. */
class SyncBookingTest extends TestCase
{
    use BuildsShipments, RefreshDatabase;

    private ClientApp $client;

    private FakeCourier $fake;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeDefaultAccount();
        $this->client = ClientApp::factory()->create();
        $this->fake = FakeCourier::make('quadx');
        CourierRegistry::fake('quadx', $this->fake);
    }

    private function creator(): ShipmentCreator
    {
        return app(ShipmentCreator::class);
    }

    private function draft(string $ref = '4211'): Shipment
    {
        return $this->creator()->create($this->client, $this->shipmentBody(['client_reference' => $ref, 'submit' => false]));
    }

    public function test_submit_now_books_synchronously_with_fake_courier(): void
    {
        $this->fake->booksAs('QX-SYNC-1');
        $draft = $this->draft();

        $s = $this->creator()->submit($draft, now: true);

        $this->assertSame('booked', $s->state);
        $this->assertSame('QX-SYNC-1', $s->tracking_number);
        $this->assertCount(1, $this->fake->callsTo('createShipment'));
        $this->assertSame(0, DB::table('jobs')->count(), 'nothing left on a queue');
    }

    public function test_create_now_books_synchronously(): void
    {
        $this->fake->booksAs('QX-SYNC-2');

        $s = $this->creator()->create($this->client, $this->shipmentBody(['client_reference' => 'C1']), 'manual', now: true);

        $this->assertSame('booked', $s->state);
        $this->assertSame('QX-SYNC-2', $s->tracking_number);
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_submit_now_refused_sets_failed(): void
    {
        $this->fake->refusesWith('Barangay not serviceable');

        $s = $this->creator()->submit($this->draft(), now: true);

        $this->assertSame('failed', $s->state);
        $this->assertSame('courier_refused', $s->error_code);
        $this->assertSame('Barangay not serviceable', $s->error_message);
        $this->assertFalse($s->needs_attention);
    }

    public function test_submit_now_timeout_sets_unknown_with_attention_and_is_not_retried(): void
    {
        $this->fake->throws(new ConnectionException('cURL error 28: timed out'));

        $s = $this->creator()->submit($this->draft(), now: true);

        $this->assertSame('unknown', $s->state);
        $this->assertTrue($s->needs_attention);
        $this->assertNotNull($s->attention_reason);
        $this->assertCount(1, $this->fake->callsTo('createShipment'));
        $this->assertSame(0, DB::table('jobs')->count(), 'not queued for a retry');
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    public function test_submit_now_throwable_escaping_the_job_is_not_thrown_to_the_caller(): void
    {
        $draft = $this->draft();
        Cache::shouldReceive('lock')->andThrow(new RuntimeException('cache down'));

        $s = $this->creator()->submit($draft, now: true);

        $this->assertSame('queued', $s->state, 'never started, so never sent');
        $this->assertSame([], $this->fake->callsTo('createShipment'));
    }

    public function test_api_create_still_queues(): void
    {
        Queue::fake();

        $s = $this->creator()->create($this->client, $this->shipmentBody(['client_reference' => 'A1']));
        $draft = $this->draft('A2');
        $this->creator()->submit($draft);

        Queue::assertPushed(BookShipment::class, 2);
        $this->assertSame('queued', $s->fresh()->state);
        $this->assertSame('queued', $draft->fresh()->state);
        $this->assertSame([], $this->fake->callsTo('createShipment'));
    }

    public function test_sync_book_while_job_queued_books_once(): void
    {
        $draft = $this->draft();
        $this->creator()->submit($draft); // the API path: a job waits on the couriers queue
        $this->assertSame(1, DB::table('jobs')->where('queue', 'couriers')->count());

        // A second submit (sync) is refused by the draft guard.
        try {
            $this->creator()->submit($draft, now: true);
            $this->fail('a queued shipment cannot be submitted again');
        } catch (ApiError $e) {
            $this->assertSame('not_draft', $e->errorCode);
        }

        // The sync path itself while the job waits: BookShipment's state guard stops the queued job.
        BookShipment::dispatchSync($draft->id);
        $this->artisan('queue:work', ['connection' => 'couriers', '--queue' => 'couriers', '--once' => true, '--stop-when-empty' => true])
            ->assertExitCode(0);

        $this->assertSame('booked', $draft->fresh()->state);
        $this->assertCount(1, $this->fake->callsTo('createShipment'));
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_sync_book_while_another_holds_the_lock_does_not_book(): void
    {
        $draft = $this->draft();
        $lock = Cache::lock("shipment:{$draft->id}", 600);
        $this->assertTrue($lock->get());

        $s = $this->creator()->submit($draft, now: true);

        $this->assertSame('queued', $s->state);
        $this->assertSame([], $this->fake->callsTo('createShipment'));
        $lock->release();
    }

    public function test_cancel_now_reaches_courier_synchronously(): void
    {
        $this->fake->booksAs('QX-C1');
        $s = $this->creator()->submit($this->draft(), now: true);
        $this->assertSame('booked', $s->state);

        $row = app(Canceller::class)->request($s, now: true);

        $this->assertSame('cancelled', $row->state);
        $this->assertSame('cancelled', $row->cancel_state);
        $this->assertSame(['QX-C1'], $this->fake->callsTo('cancelShipment')[0]['args'] ?? null);
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_cancel_now_timeout_is_failed_with_attention_not_thrown(): void
    {
        $this->fake->booksAs('QX-C2');
        $s = $this->creator()->submit($this->draft(), now: true);
        $this->fake->throws(new ConnectionException('timed out'), 'cancelShipment');

        $row = app(Canceller::class)->request($s, now: true);

        $this->assertSame('booked', $row->state);
        $this->assertSame('failed', $row->cancel_state);
        $this->assertTrue($row->needs_attention);
        $this->assertSame(Canceller::OUTCOME_UNKNOWN_REASON, $row->attention_reason);
    }

    public function test_api_cancel_still_queues(): void
    {
        $this->fake->booksAs('QX-C3');
        $s = $this->creator()->submit($this->draft(), now: true);
        Queue::fake();

        $row = app(Canceller::class)->request($s);

        Queue::assertPushed(CancelAtCourier::class, 1);
        $this->assertSame('requested', $row->cancel_state);
        $this->assertSame([], $this->fake->callsTo('cancelShipment'));
    }

    public function test_now_inside_an_open_transaction_is_a_programming_error(): void
    {
        $draft = $this->draft();
        $this->fake->booksAs('QX-T1');
        $booked = $this->creator()->submit($this->draft('T2'), now: true);

        foreach ([
            fn () => $this->creator()->submit($draft, now: true),
            fn () => $this->creator()->create($this->client, $this->shipmentBody(['client_reference' => 'T3']), 'manual', now: true),
            fn () => app(Canceller::class)->request($booked, now: true),
        ] as $i => $call) {
            try {
                DB::transaction($call);
                $this->fail("call {$i} must refuse an open transaction");
            } catch (\LogicException) {
            }
        }

        $this->assertSame('draft', $draft->fresh()->state);
        $this->assertNull($booked->fresh()->cancel_state);
        $this->assertSame(0, Shipment::query()->where('client_reference', 'T3')->count());
        $this->assertCount(1, $this->fake->callsTo('createShipment'));
        $this->assertSame([], $this->fake->callsTo('cancelShipment'));
    }
}
