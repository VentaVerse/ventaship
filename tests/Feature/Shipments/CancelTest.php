<?php

namespace Tests\Feature\Shipments;

use App\Couriers\CourierRegistry;
use App\Couriers\FakeCourier;
use App\Models\CourierAccount;
use App\Models\CourierCall;
use App\Models\OutgoingEvent;
use App\Models\Shipment;
use App\Shipments\BookingOutcomes;
use App\Shipments\Canceller;
use App\Shipments\Jobs\BookShipment;
use App\Shipments\Jobs\CancelAtCourier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\Concerns\BuildsCourierAccounts;
use Tests\Concerns\CallsClientApi;
use Tests\TestCase;

class CancelTest extends TestCase
{
    use BuildsCourierAccounts, RefreshDatabase, CallsClientApi;

    private array $client;

    private CourierAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = $this->makeClient();
        $this->account = CourierAccount::factory()->create(['courier' => 'quadx', 'status' => 'active']);
    }

    public function test_job_is_configured_never_to_retry(): void
    {
        $job = new CancelAtCourier(7);

        $this->assertSame('couriers', $job->connection);
        $this->assertSame('couriers', $job->queue);
        $this->assertSame(1, $job->tries);
    }

    public function test_queued_cancels_without_courier_call(): void
    {
        Queue::fake();
        $fake = FakeCourier::make();
        CourierRegistry::fake('quadx', $fake);
        $s = $this->shipment(['state' => 'queued', 'payment_method' => 'cod', 'payment_state' => 'cod_unpaid', 'cod_amount' => 500]);

        $this->cancel($s)->assertOk()
            ->assertJsonPath('data.state', 'cancelled')
            ->assertJsonPath('data.cancel_state', 'cancelled')
            ->assertJsonPath('data.payment_state', 'cod_void');

        $s->refresh();
        $this->assertSame([], $fake->calls);
        $this->assertNull($s->active_reference);
        $this->assertNotNull($s->cancelled_at);
        $this->assertSame('shipment.cancelled', OutgoingEvent::query()->sole()->type);
        Queue::assertNothingPushed();

        // A BookShipment that was already on the queue finds nothing to book.
        Bus::dispatchNow(new BookShipment($s->id));
        $this->assertSame([], $fake->calls);
        $this->assertSame('cancelled', $s->refresh()->state);
    }

    public function test_draft_cancels_straight_away(): void
    {
        $s = $this->shipment(['state' => 'draft']);

        $this->cancel($s)->assertOk()->assertJsonPath('data.state', 'cancelled');
    }

    public function test_booking_marks_requested_then_cancels_after_book(): void
    {
        $fake = FakeCourier::make()->booksAs('QX-9');
        CourierRegistry::fake('quadx', $fake);
        $s = $this->shipment(['state' => 'booking', 'booking_started_at' => now()]);

        $this->cancel($s)->assertStatus(202)->assertJsonPath('data.cancel_state', 'requested');
        $this->assertSame('booking', $s->refresh()->state);
        $this->assertSame([], $fake->calls);
        $this->assertSame(0, OutgoingEvent::query()->count());

        // The booking comes back: the cancel is queued for the courier.
        Queue::fake();
        app(BookingOutcomes::class)->booked($s->id, 'QX-9', 'pending', [], null);
        Queue::assertPushedOn('couriers', CancelAtCourier::class, fn (CancelAtCourier $job) => $job->shipmentId === $s->id);
        $this->assertSame('booked', $s->refresh()->state);
        $this->assertSame([], $fake->callsTo('cancelShipment'));
        Bus::dispatchNow(new CancelAtCourier($s->id));

        $s->refresh();
        $this->assertSame('cancelled', $s->state);
        $this->assertSame('cancelled', $s->cancel_state);
        $this->assertSame([['QX-9']], array_column($fake->callsTo('cancelShipment'), 'args'));
        $this->assertSame(['shipment.status_changed', 'shipment.booked', 'shipment.cancelled'],
            OutgoingEvent::query()->orderBy('id')->pluck('type')->all());
    }

    public function test_booking_refused_while_cancel_requested_ends_failed_and_cancelled(): void
    {
        $fake = FakeCourier::make()->refusesWith('Barangay not serviceable');
        CourierRegistry::fake('quadx', $fake);
        $s = $this->shipment(['state' => 'booking', 'booking_started_at' => now(), 'cancel_state' => 'requested', 'payment_method' => 'cod', 'payment_state' => 'cod_unpaid']);

        app(BookingOutcomes::class)->failed($s->id, 'courier_refused', 'Barangay not serviceable');

        $s->refresh();
        $this->assertSame('failed', $s->state);
        $this->assertSame('cancelled', $s->cancel_state);
        $this->assertNull($s->active_reference);
        $this->assertSame('cod_void', $s->payment_state);
        $this->assertFalse($s->needs_attention);
        $this->assertSame([], $fake->callsTo('cancelShipment'));
        $this->assertSame(['shipment.booking_failed', 'shipment.cancelled'], OutgoingEvent::query()->orderBy('id')->pluck('type')->all());
    }

    public function test_booking_unknown_while_cancel_requested_stays_requested_for_the_operator(): void
    {
        $s = $this->shipment(['state' => 'booking', 'booking_started_at' => now(), 'cancel_state' => 'requested']);

        app(BookingOutcomes::class)->unknown($s->id);

        $s->refresh();
        $this->assertSame('unknown', $s->state);
        $this->assertSame('requested', $s->cancel_state);
        $this->assertTrue($s->needs_attention);
    }

    public function test_cancel_of_unknown_is_requested_with_attention_and_no_courier_call(): void
    {
        $fake = FakeCourier::make();
        CourierRegistry::fake('quadx', $fake);
        $s = $this->shipment(['state' => 'unknown', 'needs_attention' => true]);

        $this->cancel($s)->assertStatus(202)->assertJsonPath('data.cancel_state', 'requested')->assertJsonPath('data.needs_attention', true);

        $this->assertSame([], $fake->calls);
        $this->assertSame('unknown', $s->refresh()->state);
    }

    public function test_operator_resolving_unknown_as_not_booked_settles_a_requested_cancel(): void
    {
        $s = $this->shipment(['state' => 'unknown', 'cancel_state' => 'requested', 'needs_attention' => true]);

        app(BookingOutcomes::class)->resolveNotBooked($s->id);

        $s->refresh();
        $this->assertSame('failed', $s->state);
        $this->assertSame('cancelled', $s->cancel_state);
        $this->assertFalse($s->needs_attention);
    }

    public function test_operator_attaching_a_tracking_number_runs_a_requested_cancel(): void
    {
        $fake = FakeCourier::make();
        CourierRegistry::fake('quadx', $fake);
        $s = $this->shipment(['state' => 'unknown', 'cancel_state' => 'requested', 'needs_attention' => true]);

        Queue::fake();
        app(BookingOutcomes::class)->resolveBooked($s->id, 'QX-5');
        Queue::assertPushed(CancelAtCourier::class);
        Bus::dispatchNow(new CancelAtCourier($s->id));

        $this->assertSame('cancelled', $s->refresh()->state);
        $this->assertSame([['QX-5']], array_column($fake->callsTo('cancelShipment'), 'args'));
    }

    public function test_booked_cancelable_calls_courier(): void
    {
        $fake = FakeCourier::make();
        CourierRegistry::fake('quadx', $fake);
        $s = $this->booked(['payment_method' => 'cod', 'payment_state' => 'cod_unpaid', 'cod_amount' => 500]);

        // 202: the courier is told by the queued job, so the answer is the request, not the outcome.
        $this->cancelAndRunJob($s)->assertStatus(202)->assertJsonPath('data.cancel_state', 'requested');

        $s->refresh();
        $this->assertSame('cancelled', $s->state);
        $this->assertSame('cancelled', $s->cancel_state);
        $this->assertSame('cod_void', $s->payment_state);
        $this->assertSame([['QX-1']], array_column($fake->callsTo('cancelShipment'), 'args'));
        $this->assertNull($s->active_reference);
        $this->assertNotNull($s->cancelled_at);
        $this->assertFalse($s->needs_attention);
        $event = OutgoingEvent::query()->sole();
        $this->assertSame('shipment.cancelled', $event->type);
        $this->assertSame('cancelled', $event->payload['shipment']['state']);
        $this->assertNull(Context::get('shipment_id'));
        $this->assertSame(0, CourierCall::query()->count());
    }

    public function test_lbc_is_unsupported_and_needs_attention(): void
    {
        $lbc = CourierAccount::factory()->create(['courier' => 'lbc', 'status' => 'active']);
        $s = $this->booked(['courier' => 'lbc', 'courier_account_id' => $lbc->id, 'courier_status' => 'unconfirmed']);

        $this->cancel($s)->assertOk()
            ->assertJsonPath('data.state', 'booked')
            ->assertJsonPath('data.cancel_state', 'unsupported')
            ->assertJsonPath('data.needs_attention', true);

        $s->refresh();
        $this->assertNotNull($s->attention_reason);
        $this->assertNotNull($s->active_reference);
        $this->assertSame('shipment.cancel_failed', OutgoingEvent::query()->sole()->type);
    }

    public function test_not_cancelable_is_refused(): void
    {
        $fake = FakeCourier::make();
        CourierRegistry::fake('quadx', $fake);
        $s = $this->booked(['courier_status' => 'delivered', 'phase' => 'delivered']);

        $this->cancel($s)->assertOk()
            ->assertJsonPath('data.state', 'booked')
            ->assertJsonPath('data.cancel_state', 'refused')
            ->assertJsonPath('data.needs_attention', true);

        $this->assertSame([], $fake->calls);
        $this->assertSame('shipment.cancel_failed', OutgoingEvent::query()->sole()->type);
    }

    public function test_courier_refusal_is_recorded_as_refused(): void
    {
        CourierRegistry::fake('quadx', FakeCourier::make()->cancels(false));
        $s = $this->booked();

        $this->cancelAndRunJob($s)->assertStatus(202);

        $s->refresh();
        $this->assertSame('refused', $s->cancel_state);
        $this->assertSame('booked', $s->state);
        $this->assertTrue($s->needs_attention);
        $this->assertSame('The test courier refused to cancel.', $s->attention_reason);
        $this->assertNotNull($s->active_reference);
        $this->assertSame('shipment.cancel_failed', OutgoingEvent::query()->sole()->type);
    }

    public function test_courier_exception_marks_cancel_failed_and_never_retries(): void
    {
        $fake = FakeCourier::make()->throws(new RuntimeException('cURL error 28: timed out'), 'cancelShipment');
        CourierRegistry::fake('quadx', $fake);
        $s = $this->booked();

        $this->cancelAndRunJob($s)->assertStatus(202);

        $s->refresh();
        $this->assertSame('failed', $s->cancel_state);
        $this->assertSame('booked', $s->state);
        $this->assertTrue($s->needs_attention);
        $this->assertSame('Cancel outcome unknown — check the courier portal', $s->attention_reason);
        $this->assertCount(1, $fake->callsTo('cancelShipment'));
        $this->assertSame('shipment.cancel_failed', OutgoingEvent::query()->sole()->type);

        // A second request or a second run of the job changes nothing and calls nobody.
        $this->cancel($s)->assertOk()->assertJsonPath('data.cancel_state', 'failed');
        Bus::dispatchNow(new CancelAtCourier($s->id));
        $this->assertCount(1, $fake->callsTo('cancelShipment'));
        $this->assertSame(1, OutgoingEvent::query()->count());
    }

    public function test_a_failure_preparing_the_cancel_is_failed_not_refused(): void
    {
        // Seam: the courier cannot even be built for this account, before any courier contact.
        $bad = CourierAccount::factory()->create(['courier' => 'nosuch', 'status' => 'active']);
        $s = $this->booked(['courier_account_id' => $bad->id]);

        $this->cancel($s)->assertOk()->assertJsonPath('data.cancel_state', 'failed');

        $s->refresh();
        $this->assertSame('booked', $s->state);
        $this->assertTrue($s->needs_attention);
        $this->assertSame('Cancel could not be prepared — check the account settings', $s->attention_reason);
        $this->assertSame('shipment.cancel_failed', OutgoingEvent::query()->sole()->type);
    }

    public function test_successful_cancel_keeps_an_unrelated_attention_reason(): void
    {
        CourierRegistry::fake('quadx', FakeCourier::make());
        $s = $this->booked(['needs_attention' => true, 'attention_reason' => 'LBC did not confirm the pickup request']);

        $this->cancelAndRunJob($s);

        $s->refresh();
        $this->assertSame('cancelled', $s->state);
        $this->assertTrue($s->needs_attention);
        $this->assertSame('LBC did not confirm the pickup request', $s->attention_reason);
    }

    public function test_cancel_of_a_shipment_the_courier_already_cancelled_settles_it(): void
    {
        $fake = FakeCourier::make();
        CourierRegistry::fake('quadx', $fake);
        $s = $this->booked(['courier_status' => 'canceled', 'active_reference' => '4211']);

        $this->cancel($s)->assertOk()
            ->assertJsonPath('data.state', 'cancelled')
            ->assertJsonPath('data.cancel_state', 'cancelled')
            ->assertJsonPath('data.needs_attention', false);

        $this->assertSame([], $fake->calls);
        $this->assertNull($s->refresh()->active_reference);
        $this->assertSame('shipment.cancelled', OutgoingEvent::query()->sole()->type);
    }

    public function test_cancel_job_settles_when_the_courier_status_is_already_cancelled(): void
    {
        $fake = FakeCourier::make();
        CourierRegistry::fake('quadx', $fake);
        $s = $this->booked(['courier_status' => 'canceled', 'cancel_state' => 'requested', 'cancel_requested_at' => now()]);

        Bus::dispatchNow(new CancelAtCourier($s->id));

        $s->refresh();
        $this->assertSame('cancelled', $s->state);
        $this->assertSame('cancelled', $s->cancel_state);
        $this->assertSame([], $fake->callsTo('cancelShipment'));
        $this->assertSame('shipment.cancelled', OutgoingEvent::query()->sole()->type);
    }

    public function test_a_locked_live_spx_cancel_is_refused_and_nothing_is_sent(): void
    {
        config(['couriers.spx_live' => false]);
        $spx = $this->spxAccount(['mode' => 'production', 'status' => 'active', 'allow_live_booking' => true]);
        $s = $this->booked(['courier' => 'spx', 'courier_account_id' => $spx->id, 'tracking_number' => 'SPXPH000001', 'courier_status' => null]);

        $this->cancelAndRunJob($s)->assertStatus(202);

        $s->refresh();
        $this->assertSame('booked', $s->state);
        $this->assertSame('refused', $s->cancel_state);
        $this->assertTrue($s->needs_attention);
        $this->assertSame('Live cancels are locked for this account — nothing was sent to the courier', $s->attention_reason);
        $this->assertNotNull($s->active_reference);
        $this->assertSame('shipment.cancel_failed', OutgoingEvent::query()->sole()->type);
        Http::assertNothingSent();
    }

    public function test_failed_hook_marks_a_stuck_cancel_failed(): void
    {
        $s = $this->booked(['cancel_state' => 'requested']);

        (new CancelAtCourier($s->id))->failed(new RuntimeException('timed out'));

        $s->refresh();
        $this->assertSame('failed', $s->cancel_state);
        $this->assertTrue($s->needs_attention);
    }

    public function test_cancel_is_idempotent(): void
    {
        $fake = FakeCourier::make();
        CourierRegistry::fake('quadx', $fake);
        $s = $this->booked();

        $this->cancelAndRunJob($s)->assertStatus(202);
        $this->cancel($s)->assertOk()->assertJsonPath('data.state', 'cancelled');

        $this->assertCount(1, $fake->callsTo('cancelShipment'));
        $this->assertSame(1, OutgoingEvent::query()->count());
    }

    public function test_cancel_while_a_cancel_is_in_flight_does_not_dispatch_again(): void
    {
        Queue::fake();
        $s = $this->booked(['cancel_state' => 'requested']);

        $this->cancel($s)->assertStatus(202);

        Queue::assertNothingPushed();
    }

    public function test_cancel_of_a_failed_shipment_changes_nothing(): void
    {
        $s = $this->shipment(['state' => 'failed', 'active_reference' => null]);

        $this->cancel($s)->assertOk()->assertJsonPath('data.state', 'failed')->assertJsonPath('data.cancel_state', null);

        $this->assertSame(0, OutgoingEvent::query()->count());
    }

    public function test_other_clients_shipment_is_a_404(): void
    {
        $other = $this->makeClient();
        $s = $this->shipment(['state' => 'queued', 'client_app_id' => $other['app']->id]);

        $this->cancel($s)->assertNotFound();
        $this->assertSame('queued', $s->refresh()->state);
    }

    public function test_cancel_requires_an_idempotency_key(): void
    {
        $s = $this->shipment(['state' => 'queued']);

        $this->apiJson('POST', "/api/v1/shipments/{$s->public_id}/cancel", [], [], $this->client)
            ->assertStatus(422);
        $this->assertSame('queued', $s->refresh()->state);
    }

    public function test_cancelled_payment_state_for_prepaid_stays_prepaid(): void
    {
        $s = $this->shipment(['state' => 'queued', 'payment_method' => 'prepaid', 'payment_state' => 'prepaid']);

        app(Canceller::class)->request($s);

        $this->assertSame('prepaid', $s->refresh()->payment_state);
    }

    /** The request queues CancelAtCourier on the couriers queue; run it the way a worker would. */
    private function cancelAndRunJob(Shipment $s): TestResponse
    {
        Queue::fake();
        $res = $this->cancel($s);
        Queue::assertPushedOn('couriers', CancelAtCourier::class, fn (CancelAtCourier $job) => $job->shipmentId === $s->id);
        Bus::dispatchNow(new CancelAtCourier($s->id));

        return $res;
    }

    private function cancel(Shipment $s): TestResponse
    {
        return $this->apiJson('POST', "/api/v1/shipments/{$s->public_id}/cancel", [],
            ['Idempotency-Key' => (string) Str::uuid()], $this->client);
    }

    private function shipment(array $attributes = []): Shipment
    {
        return Shipment::factory()->create($attributes + [
            'client_app_id' => $this->client['app']->id,
            'courier_account_id' => $this->account->id,
            'courier_ref' => 'VS000001',
            'state' => 'queued',
        ]);
    }

    private function booked(array $attributes = []): Shipment
    {
        return $this->shipment($attributes + [
            'state' => 'booked',
            'tracking_number' => 'QX-1',
            'courier_status' => 'pending',
            'booked_at' => now(),
        ]);
    }
}
