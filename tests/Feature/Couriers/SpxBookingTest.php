<?php

namespace Tests\Feature\Couriers;

use App\Couriers\AccountSettings;
use App\Couriers\CourierOrder;
use App\Couriers\CourierRegistry;
use App\Couriers\PickupPoint;
use App\Couriers\Spx\SpxClient;
use App\Couriers\Spx\SpxFulfillment;
use App\Fulfillment\ShipmentDetail;
use App\Http\Resources\ShipmentResource;
use App\Models\ClientApp;
use App\Models\CourierAccount;
use App\Models\OutgoingEvent;
use App\Models\Shipment;
use App\Shipments\Jobs\BookShipment;
use App\Shipments\Poller;
use App\Shipments\TrackingLink;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsCourierAccounts;
use Tests\TestCase;

/**
 * SPX booking, end to end through the real adapter with only HTTP faked:
 * a pickup without a slot books SPX's earliest window, a stale or past-cutoff window is replaced once, 13103 on a
 * retry finds the existing order, SPX webhooks repair a failed or unknown shipment, and SPX's coverage codes.
 */
class SpxBookingTest extends TestCase
{
    use BuildsCourierAccounts, RefreshDatabase;

    private const APP_ID = 424242;

    private const APP_SECRET = 'fake-app-secret-not-real';

    private const TRACKING = 'SPEPH00000001234B';

    private CourierAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-10 08:00', 'Asia/Manila'));
        $this->account = $this->spxAccount(['external_ref' => (string) self::APP_ID]);
    }

    // ---------------------------------------------------------------- #4 a pickup always has a window

    public function test_a_pickup_without_a_slot_books_the_earliest_window_and_stores_it(): void
    {
        $this->fakeSpx(slots: [$this->day('2026-11-10', ['2:00pm-4:00pm' => 2, 'Now' => 0]), $this->day('2026-11-11', ['10:00am-12:00pm' => 1])]);
        $s = $this->queuedShipment();

        Bus::dispatchSync(new BookShipment($s->id));

        $s->refresh();
        $this->assertSame('booked', $s->state);
        $this->assertSame('pickup', $s->method);
        $sent = $this->created()[0]['fulfillment_info'];
        $this->assertSame(1, $sent['collect_type']);
        $this->assertSame($this->time('2026-11-10'), $sent['pickup_time']);
        $this->assertSame(0, $sent['pickup_time_range_id']);
        $this->assertSame('Now', $sent['pickup_time_range']);

        $stored = json_decode($s->options['pickup_slot'], true);
        $this->assertSame(['pickup_time' => $this->time('2026-11-10'), 'pickup_time_range_id' => 0, 'pickup_time_range' => 'Now'], $stored);
        $this->assertSame($s->options['pickup_slot'], (new ShipmentResource($s))->toArray()['pickup_slot']);
        $this->assertSame('chosen', $s->meta['pickup_changes'][0]['reason']);

        $detail = new ShipmentDetail($s->load('events'));
        $this->assertSame('Tue, Nov 10 · Now', $detail->courierRows()['Pickup time']);
        $this->assertContains('Pickup time set · Tue, Nov 10 · Now', array_column($detail->timeline(), 'label'));
    }

    public function test_a_pickup_spx_has_no_window_for_fails_clearly_and_is_never_a_drop_off(): void
    {
        $this->fakeSpx(slots: []);
        $s = $this->queuedShipment();

        Bus::dispatchSync(new BookShipment($s->id));

        $s->refresh();
        $this->assertSame('failed', $s->state);
        $this->assertSame('courier_refused', $s->error_code);
        $this->assertSame('SPX has no pickup time available for this address.', $s->error_message);
        $this->assertSame([], $this->created(), 'nothing was booked, least of all a drop-off');
    }

    public function test_pickup_times_spx_cannot_be_asked_for_fail_the_booking_before_anything_is_sent(): void
    {
        Http::fake(['*get_pickup_time*' => Http::failedConnection(), '*batch_create_order*' => Http::response([], 500)]);
        $s = $this->queuedShipment();

        Bus::dispatchSync(new BookShipment($s->id));

        $s->refresh();
        $this->assertSame('failed', $s->state);
        $this->assertSame("Couldn't get SPX's pickup times. Try again.", $s->error_message);
        $this->assertSame([], $this->created());
    }

    public function test_a_drop_off_sends_collect_type_2_and_never_asks_for_pickup_times(): void
    {
        $this->fakeSpx(slots: [$this->day('2026-11-10', ['Now' => 0])]);
        $s = $this->queuedShipment(['method' => 'dropoff', 'options' => ['parcel' => ['weight_kg' => 1], 'pickup_slot' => $this->slot('2026-11-10', 0, 'Now')]]);

        Bus::dispatchSync(new BookShipment($s->id));

        $this->assertSame('booked', $s->fresh()->state);
        $this->assertSame(2, $this->created()[0]['fulfillment_info']['collect_type']);
        $this->assertArrayNotHasKey('pickup_time', $this->created()[0]['fulfillment_info']);
        $this->assertSame(0, $this->pickupTimeCalls());
    }

    // ---------------------------------------------------------------- #16 stale windows

    public function test_a_chosen_window_is_sent_unchanged_when_it_is_still_current(): void
    {
        $this->fakeSpx(slots: [$this->day('2026-11-10', ['Now' => 0])]);
        $slot = $this->slot('2026-11-11', 3, '4:00pm-6:00pm');
        $s = $this->queuedShipment(['options' => ['parcel' => ['weight_kg' => 1], 'pickup_slot' => $slot]]);

        Bus::dispatchSync(new BookShipment($s->id));

        $this->assertSame('booked', $s->fresh()->state);
        $this->assertSame(3, $this->created()[0]['fulfillment_info']['pickup_time_range_id']);
        $this->assertSame(0, $this->pickupTimeCalls());
        $this->assertSame($slot, $s->fresh()->options['pickup_slot']);
        $this->assertArrayNotHasKey('pickup_changes', $s->fresh()->meta);
    }

    public function test_a_window_on_a_day_already_over_is_replaced_by_the_earliest(): void
    {
        $this->fakeSpx(slots: [$this->day('2026-11-11', ['10:00am-12:00pm' => 1])]);
        $s = $this->queuedShipment(['options' => ['parcel' => ['weight_kg' => 1], 'pickup_slot' => $this->slot('2026-11-09', 2, '2:00pm-4:00pm')]]);

        Bus::dispatchSync(new BookShipment($s->id));

        $s->refresh();
        $this->assertSame('booked', $s->state);
        $this->assertSame($this->time('2026-11-11'), $this->created()[0]['fulfillment_info']['pickup_time']);
        $this->assertSame('passed', $s->meta['pickup_changes'][0]['reason']);
        $this->assertContains('Pickup time moved · Wed, Nov 11 · 10:00am-12:00pm', array_column((new ShipmentDetail($s->load('events')))->timeline(), 'label'));
    }

    public function test_10080_asks_spx_again_once_and_books_the_next_window(): void
    {
        $this->fakeSpx(
            slots: [$this->day('2026-11-10', ['2:00pm-4:00pm' => 2, '4:00pm-6:00pm' => 3])],
            results: [$this->refusal(10080, 'now_time over cutoff_time'), $this->done()],
        );
        $s = $this->queuedShipment(['options' => ['parcel' => ['weight_kg' => 1], 'pickup_slot' => $this->slot('2026-11-10', 2, '2:00pm-4:00pm')]]);

        Bus::dispatchSync(new BookShipment($s->id));

        $s->refresh();
        $this->assertSame('booked', $s->state);
        $this->assertSame([2, 3], array_map(fn ($o) => $o['fulfillment_info']['pickup_time_range_id'], $this->created()));
        $this->assertSame(1, $this->pickupTimeCalls());
        $this->assertSame(3, json_decode($s->options['pickup_slot'], true)['pickup_time_range_id']);
        $this->assertSame('cutoff', $s->meta['pickup_changes'][0]['reason']);
        $this->assertContains('Pickup time moved · Tue, Nov 10 · 4:00pm-6:00pm', array_column((new ShipmentDetail($s->load('events')))->timeline(), 'label'));
    }

    public function test_a_second_10080_fails_with_both_windows_named(): void
    {
        $this->fakeSpx(
            slots: [$this->day('2026-11-10', ['2:00pm-4:00pm' => 2, '4:00pm-6:00pm' => 3])],
            results: [$this->refusal(10080, 'now_time over cutoff_time'), $this->refusal(10080, 'now_time over cutoff_time')],
        );
        $s = $this->queuedShipment(['options' => ['parcel' => ['weight_kg' => 1], 'pickup_slot' => $this->slot('2026-11-10', 2, '2:00pm-4:00pm')]]);

        Bus::dispatchSync(new BookShipment($s->id));

        $s->refresh();
        $this->assertSame('failed', $s->state);
        $this->assertSame("SPX refused the pickup times Tue, Nov 10 · 2:00pm-4:00pm and Tue, Nov 10 · 4:00pm-6:00pm as past its cutoff. Choose another pickup time. (SPX 10080)", $s->error_message);
        $this->assertCount(2, $this->created(), 'retried once, never more');
        $this->assertSame(1, $this->pickupTimeCalls());
    }

    public function test_10080_with_no_later_window_fails_clearly(): void
    {
        $this->fakeSpx(slots: [$this->day('2026-11-10', ['2:00pm-4:00pm' => 2])], results: [$this->refusal(10080, 'now_time over cutoff_time')]);
        $s = $this->queuedShipment(['options' => ['parcel' => ['weight_kg' => 1], 'pickup_slot' => $this->slot('2026-11-10', 2, '2:00pm-4:00pm')]]);

        Bus::dispatchSync(new BookShipment($s->id));

        $this->assertSame("The pickup time Tue, Nov 10 · 2:00pm-4:00pm is past SPX's cutoff, and SPX has no later pickup time for this address.", $s->fresh()->error_message);
        $this->assertCount(1, $this->created());
    }

    // ---------------------------------------------------------------- #5 13103 on a retry

    public function test_13103_on_a_retry_finds_the_order_in_the_earlier_batch_and_books_it(): void
    {
        $this->fakeSpx(results: [$this->refusal(13103, 'order id used'), $this->done(batch: 7001)], batch: 7002);
        $s = $this->queuedShipment(['method' => 'dropoff', 'meta' => ['batch_no' => 7001]]);

        Bus::dispatchSync(new BookShipment($s->id));

        $s->refresh();
        $this->assertSame('booked', $s->state);
        $this->assertSame(self::TRACKING, $s->tracking_number);
        $this->assertSame('55.00', $s->fee_amount);
        $this->assertSame(7001, $s->meta['batch_no'], 'the batch that created the order, for the label');
        $this->assertTrue($s->needs_attention);
        $this->assertSame('SPX already had order VS000042 from an earlier attempt: check its details in the SPX portal', $s->attention_reason);
        $this->assertSame('https://uat.spx.ph/track?'.self::TRACKING, TrackingLink::for($s));
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'get_order_create_result') && $r['batch_no'] === 7001);
    }

    public function test_13103_without_an_earlier_batch_finds_the_order_by_track_on_its_order_id(): void
    {
        $this->fakeSpx(results: [$this->refusal(13103, 'order id used')], track: [
            'orders' => [['order_id' => 'VS000042', 'tracking_no' => self::TRACKING, 'status_code' => '1001',
                'tracking_link' => 'https://uat.spx.ph/track?'.self::TRACKING, 'deliver_info' => ['deliver_name' => 'Jane Doe', 'deliver_phone' => '639171234567']]],
            'fail_list' => [],
        ]);
        $s = $this->queuedShipment(['method' => 'dropoff']);

        Bus::dispatchSync(new BookShipment($s->id));

        $s->refresh();
        $this->assertSame('booked', $s->state);
        $this->assertSame(self::TRACKING, $s->tracking_number);
        $this->assertSame('1001', $s->courier_status);
        $this->assertArrayNotHasKey('deliver_info', $s->meta['booked_response'], 'Track\'s personal data is not kept');
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'batch_search_order') && $r['tracking_no_list'] === ['VS000042']);
        $this->assertSame(1, OutgoingEvent::query()->where('type', 'shipment.booked')->count());
    }

    public function test_13103_with_a_track_answer_for_another_order_is_not_trusted(): void
    {
        $this->fakeSpx(results: [$this->refusal(13103, 'order id used')], track: [
            'orders' => [['order_id' => 'VS999999', 'tracking_no' => 'SPEPHOTHER0001', 'status_code' => '1001']],
            'fail_list' => [],
        ]);
        $s = $this->queuedShipment(['method' => 'dropoff']);

        Bus::dispatchSync(new BookShipment($s->id));

        $s->refresh();
        $this->assertSame('unknown', $s->state);
        $this->assertNull($s->tracking_number);
    }

    public function test_13103_with_the_same_order_id_for_another_receiver_is_not_adopted(): void
    {
        $this->fakeSpx(results: [$this->refusal(13103, 'order id used')], track: [
            'orders' => [['order_id' => 'VS000042', 'tracking_no' => 'SPEPHOTHER0001', 'status_code' => '1001',
                'deliver_info' => ['deliver_phone' => '639998887777']]],
            'fail_list' => [],
        ]);
        $s = $this->queuedShipment(['method' => 'dropoff']);

        Bus::dispatchSync(new BookShipment($s->id));

        $this->assertSame('unknown', $s->fresh()->state);
        $this->assertNull($s->fresh()->tracking_number);
    }

    public function test_13103_when_the_order_cannot_be_found_is_unknown_never_failed(): void
    {
        $this->fakeSpx(results: [$this->refusal(13103, 'order id used')], track: ['orders' => [], 'fail_list' => [['tracking_no' => 'VS000042', 'ret_code' => 13251, 'message' => 'not found']]]);
        $s = $this->queuedShipment(['method' => 'dropoff']);

        Bus::dispatchSync(new BookShipment($s->id));

        $s->refresh();
        $this->assertSame('unknown', $s->state);
        $this->assertSame('4211', $s->active_reference, 'the reference is kept: a parcel may exist');
        $this->assertTrue($s->needs_attention);
        $this->assertSame('SPX already has order VS000042: find its tracking number in the SPX portal and record it', $s->attention_reason);
    }

    public function test_13103_as_the_whole_call_refusal_is_handled_the_same(): void
    {
        Http::fake([
            '*batch_create_order*' => Http::response(['ret_code' => 13103, 'message' => 'The order id has been used already', 'data' => null]),
            '*batch_search_order*' => Http::response(['ret_code' => 0, 'message' => 'success.', 'data' => [
                'orders' => [['order_id' => 'VS000042', 'tracking_no' => self::TRACKING, 'status_code' => '1001']], 'fail_list' => [],
            ]]),
        ]);
        $s = $this->queuedShipment(['method' => 'dropoff']);

        Bus::dispatchSync(new BookShipment($s->id));

        $this->assertSame('booked', $s->fresh()->state);
        $this->assertSame(self::TRACKING, $s->fresh()->tracking_number);
    }

    public function test_13103_found_by_the_poller_resolves_a_creating_booking(): void
    {
        $this->fakeSpx(results: [$this->refusal(13103, 'order id used', batch: 9001)], track: [
            'orders' => [['order_id' => 'VS000042', 'tracking_no' => self::TRACKING, 'status_code' => '1001']], 'fail_list' => [],
        ]);
        $s = $this->queuedShipment(['state' => 'booking', 'courier_status' => 'creating', 'meta' => ['batch_no' => 9001, 'pending' => true]]);

        app(Poller::class)->run();

        $s->refresh();
        $this->assertSame('booked', $s->state);
        $this->assertSame(self::TRACKING, $s->tracking_number);
        $this->assertNull($s->meta['batch_no'], 'the refused batch is not the order\'s');
    }

    public function test_13103_the_poller_cannot_find_makes_the_booking_unknown_with_the_reason(): void
    {
        $this->fakeSpx(results: [$this->refusal(13103, 'order id used', batch: 9001)], track: ['orders' => [], 'fail_list' => []]);
        $s = $this->queuedShipment(['state' => 'booking', 'courier_status' => 'creating', 'meta' => ['batch_no' => 9001, 'pending' => true]]);

        app(Poller::class)->run();

        $s->refresh();
        $this->assertSame('unknown', $s->state);
        $this->assertSame('SPX already has order VS000042: find its tracking number in the SPX portal and record it', $s->attention_reason);
    }

    // ---------------------------------------------------------------- #5 webhooks repair failed / unknown

    public function test_an_spx_webhook_for_a_failed_shipment_books_it_and_takes_its_reference_back(): void
    {
        Http::fake();
        $s = $this->queuedShipment(['state' => 'failed', 'active_reference' => null, 'error_code' => 'courier_refused', 'error_message' => 'x']);

        $this->postSigned($this->payload())->assertOk();

        $s->refresh();
        $this->assertSame('booked', $s->state);
        $this->assertSame(self::TRACKING, $s->tracking_number);
        $this->assertSame('4211', $s->active_reference);
        $this->assertNull($s->error_code);
        $this->assertFalse($s->needs_attention);
        $this->assertSame('1001', $s->courier_status);
        $this->assertSame(1, OutgoingEvent::query()->where('type', 'shipment.booked')->count());
        Http::assertNothingSent();
    }

    public function test_an_spx_webhook_for_an_unknown_shipment_books_it(): void
    {
        Http::fake();
        $s = $this->queuedShipment(['state' => 'unknown', 'needs_attention' => true, 'attention_reason' => 'Booking outcome unknown. Check the courier portal before retrying']);

        $this->postSigned($this->payload())->assertOk();

        $s->refresh();
        $this->assertSame('booked', $s->state);
        $this->assertSame(self::TRACKING, $s->tracking_number);
        $this->assertFalse($s->needs_attention);
        $this->assertNull($s->attention_reason);
    }

    public function test_a_failed_shipment_whose_reference_is_now_live_elsewhere_is_booked_and_flagged(): void
    {
        Http::fake();
        $s = $this->queuedShipment(['state' => 'failed', 'active_reference' => null]);
        Shipment::factory()->create([
            'client_app_id' => $s->client_app_id, 'courier_account_id' => $this->account->id, 'courier' => 'spx',
            'client_reference' => '4211', 'active_reference' => '4211', 'courier_ref' => 'VS000043', 'state' => 'booked',
        ]);

        $this->postSigned($this->payload())->assertOk();

        $s->refresh();
        $this->assertSame('booked', $s->state);
        $this->assertNull($s->active_reference);
        $this->assertTrue($s->needs_attention);
        $this->assertStringContainsString('VS000043 now uses its reference', $s->attention_reason);
    }

    public function test_a_failed_shipment_whose_cancel_is_settled_stays_failed_and_is_flagged(): void
    {
        Http::fake();
        $s = $this->queuedShipment(['state' => 'failed', 'active_reference' => null, 'cancel_state' => 'cancelled']);

        $this->postSigned($this->payload())->assertOk();

        $s->refresh();
        $this->assertSame('failed', $s->state);
        $this->assertSame(self::TRACKING, $s->tracking_number);
        $this->assertTrue($s->needs_attention);
        $this->assertStringContainsString('Cancel it at the courier portal', $s->attention_reason);
    }

    public function test_a_cancelled_report_does_not_book_a_failed_shipment(): void
    {
        Http::fake();
        $s = $this->queuedShipment(['state' => 'failed', 'active_reference' => null]);

        $this->postSigned($this->payload(['status_code' => '7001', 'status' => 'Cancelled']))->assertOk();

        $this->assertSame('failed', $s->fresh()->state);
    }

    // ---------------------------------------------------------------- #12 address line 2

    public function test_address_2_reaches_spx_after_address_1_for_both_parties(): void
    {
        $this->fakeSpx();
        $s = $this->queuedShipment(['method' => 'dropoff']);
        $s->forceFill([
            'recipient' => ['address_2' => 'Unit 4, Tower B'] + $s->recipient,
            'pickup' => ['address_2' => 'Warehouse 2'] + $s->pickup,
        ])->save();

        Bus::dispatchSync(new BookShipment($s->id));

        $sent = $this->created()[0];
        $this->assertSame('123 Mango St, Unit 4, Tower B', $sent['deliver_info']['deliver_detail_address']);
        $this->assertSame('5 Shaw Blvd, Warehouse 2', $sent['sender_info']['sender_detail_address']);
    }

    // ---------------------------------------------------------------- #14 coverage codes

    /** @return array<string, array{int, string, ?string, ?string}> */
    public static function coverageCodes(): array
    {
        return [
            '10071 delivery not in basic area' => [10071, 'not_serviceable', 'destination', 'spx_no_delivery'],
            '10075 delivery not in ops area' => [10075, 'not_serviceable', 'destination', 'spx_no_delivery'],
            '10070 pickup not in basic area' => [10070, 'not_serviceable', 'origin', 'spx_no_pickup'],
            '10074 pickup not in ops area' => [10074, 'not_serviceable', 'origin', 'spx_no_pickup'],
            '10019 delivery address' => [10019, 'not_serviceable', 'destination', 'spx_no_delivery'],
            '10069 no route' => [10069, 'not_serviceable', 'destination', 'spx_no_route'],
            '10017 COD delivery' => [10017, 'not_serviceable', 'cod', 'spx_no_cod'],
            '10015 COD pickup' => [10015, 'not_serviceable', 'origin', 'spx_no_cod_pickup'],
            '10028 postcode' => [10028, 'not_serviceable', 'postcode', 'spx_invalid_postcode'],
            '10021 too far' => [10021, 'unknown', null, 'spx_too_far'],
            '10022 too near' => [10022, 'unknown', null, 'spx_too_near'],
            '10078 unmapped' => [10078, 'unknown', null, 'spx_unmapped_address'],
            '10080 cutoff' => [10080, 'unknown', 'slot', null],
            '10084 too long' => [10084, 'unknown', 'parcel', null],
            '19999 unrecognised' => [19999, 'unknown', null, null],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('coverageCodes')]
    public function test_spx_coverage_codes_map_to_verdict_codes(int $code, string $verdict, ?string $kind, ?string $verdictCode): void
    {
        Http::fake(['*batch_check_order*' => Http::response(['ret_code' => 0, 'message' => 'success.', 'data' => [
            'orders' => [], 'fail_list' => [['order_id' => 'VS000042', 'ret_code' => $code, 'message' => 'SPX says no']],
        ]])]);
        $s = $this->queuedShipment();

        $answer = $this->courier()->checkServiceability(CourierOrder::fromApi($s), PickupPoint::fromArray($s->pickup))->toArray();

        $this->assertSame($verdict, $answer['status'] ?? $answer['verdict'] ?? null);
        $this->assertSame($kind, $answer['kind']);
        $this->assertSame($verdictCode, $answer['code']);
        $this->assertSame('SPX says no', $answer['reason']);
    }

    // ---------------------------------------------------------------- public tracking URL

    public function test_the_public_tracking_url_is_the_booking_s_own_link_and_never_invented(): void
    {
        $courier = $this->courier();

        $this->assertSame('https://uat.spx.ph/track?X1', $courier->publicTrackingUrl('X1', ['booked_response' => ['tracking_link' => 'https://uat.spx.ph/track?X1']]));
        $this->assertSame('https://uat.spx.ph/track?NEW', $courier->publicTrackingUrl('X1', [
            'tracking_links' => ['delivery' => 'https://uat.spx.ph/track?NEW'], 'booked_response' => ['tracking_link' => 'https://uat.spx.ph/track?X1'],
        ]));
        $this->assertNull($courier->publicTrackingUrl('X1', []));
        $this->assertNull($courier->publicTrackingUrl('X1', ['booked_response' => ['tracking_link' => 'javascript:alert(1)']]));
    }

    // ---------------------------------------------------------------- helpers

    private function courier(): SpxFulfillment
    {
        return new SpxFulfillment($this->account->fresh(), AccountSettings::for($this->account), sleeper: static function (int $ms): void {});
    }

    /**
     * @param  list<array<string, mixed>>|null  $slots  1.1's data (null: 1.1 not faked)
     * @param  list<array<string, mixed>>  $results  1.5 answers in order (the last repeats)
     * @param  array<string, mixed>|null  $track  1.2's data
     */
    private function fakeSpx(?array $slots = null, array $results = [], ?array $track = null, int $batch = 9001): void
    {
        $results = $results === [] ? [$this->done()] : $results;
        $i = 0;
        Http::fake(function (HttpRequest $r) use ($slots, &$results, &$i, $track, $batch) {
            $url = $r->url();
            if (str_contains($url, 'get_pickup_time') && $slots !== null) {
                return Http::response(['ret_code' => 0, 'message' => 'success.', 'data' => $slots]);
            }
            if (str_contains($url, 'batch_create_order')) {
                return Http::response(['ret_code' => 0, 'message' => 'success.', 'data' => ['batch_no' => $batch]]);
            }
            if (str_contains($url, 'get_order_create_result')) {
                $answer = $results[min($i, count($results) - 1)];
                $i++;

                return Http::response($answer);
            }
            if (str_contains($url, 'batch_search_order') && $track !== null) {
                return Http::response(['ret_code' => 0, 'message' => 'success.', 'data' => $track]);
            }

            return Http::response(['ret_code' => 99999, 'message' => 'unexpected call in test: '.$url], 400);
        });
    }

    /** @return list<array<string, mixed>> every 1.4 order sent */
    private function created(): array
    {
        return Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), 'batch_create_order'))
            ->map(fn (array $pair) => $pair[0]->data()['orders'][0])->values()->all();
    }

    private function pickupTimeCalls(): int
    {
        return Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), 'get_pickup_time'))->count();
    }

    private function time(string $date): int
    {
        return CarbonImmutable::parse("{$date} 17:00", 'Asia/Manila')->getTimestamp();
    }

    /** @param  array<string, int>  $windows  range text => range id, in SPX's order */
    private function day(string $date, array $windows): array
    {
        $slots = [];
        foreach ($windows as $range => $id) {
            $slots[] = ['pickup_time_range_id' => $id, 'pickup_time_range' => $range];
        }

        return ['date' => $date, 'pickup_time' => $this->time($date), 'slots' => $slots];
    }

    private function slot(string $date, int $id, string $range): string
    {
        return json_encode(['pickup_time' => $this->time($date), 'pickup_time_range_id' => $id, 'pickup_time_range' => $range]);
    }

    /** @return array<string, mixed> */
    private function done(int $batch = 9001): array
    {
        return ['ret_code' => 0, 'message' => 'success.', 'data' => [
            'batch_no' => $batch, 'task_status' => 4, 'description' => 'Done', 'total_count' => 1, 'success_count' => 1,
            'fail_count' => 0, 'progress' => '100.00%',
            'orders' => [[
                'order_id' => 'VS000042', 'tracking_no' => self::TRACKING,
                'tracking_link' => 'https://uat.spx.ph/track?'.self::TRACKING, 'estimated_shipping_fee' => 55,
            ]],
            'fail_list' => [],
        ]];
    }

    /** @return array<string, mixed> */
    private function refusal(int $code, string $debug, int $batch = 9001): array
    {
        return ['ret_code' => 0, 'message' => 'success.', 'data' => [
            'batch_no' => $batch, 'task_status' => 4, 'description' => 'Done', 'total_count' => 1, 'success_count' => 0,
            'fail_count' => 1, 'progress' => '100.00%', 'orders' => [],
            'fail_list' => [['order_id' => 'VS000042', 'message' => 'Create Order Failed.', 'debug_msg' => $debug, 'ret_code' => $code]],
        ]];
    }

    /** @param  array<string, mixed>  $attributes */
    private function queuedShipment(array $attributes = []): Shipment
    {
        return Shipment::factory()->create($attributes + [
            'client_app_id' => ClientApp::factory(),
            'courier_account_id' => $this->account->id,
            'courier' => 'spx',
            'client_reference' => '4211',
            'active_reference' => '4211',
            'courier_ref' => 'VS000042',
            'state' => 'queued',
            'method' => 'pickup',
            'payment_method' => 'prepaid',
            'pickup' => [
                'contact_name' => 'Juan Shop', 'contact_phone' => '09181234567', 'address_1' => '5 Shaw Blvd',
                'barangay' => 'Wack-wack Greenhills', 'city' => 'Mandaluyong', 'state' => 'Metro Manila', 'postcode' => '1550',
                'country' => 'PH', 'codes' => [],
            ],
            'recipient' => [
                'first_name' => 'Jane', 'last_name' => 'Doe', 'phone' => '09171234567', 'address_1' => '123 Mango St',
                'barangay' => 'Lahug (Pob.)', 'city' => 'Cebu City', 'state' => 'Cebu', 'postcode' => '6000', 'country' => 'PH',
                'codes' => [],
            ],
            'items' => [['name' => 'Blue Tee', 'quantity' => 1, 'price' => '500.00']],
            'options' => ['parcel' => ['weight_kg' => 1]],
            'total' => 500,
        ]);
    }

    /** @param  array<string, mixed>  $overrides */
    private function payload(array $overrides = []): string
    {
        return json_encode($overrides + [
            'status' => 'Pending Pickup',
            'status_code' => '1001',
            'message' => 'x',
            'tracking_no' => self::TRACKING,
            'tracking_link' => 'https://uat.spx.ph/track?'.self::TRACKING,
            'order_id' => 'VS000042',
            'timestamp' => 1762570000,
            'id' => 'd4dcfea1-5286-4921-8fd3-7ded133f8579',
        ], JSON_UNESCAPED_SLASHES);
    }

    private function postSigned(string $raw): TestResponse
    {
        $timestamp = now()->getTimestamp();
        $sign = SpxClient::sign(self::APP_ID, $timestamp, 55, $raw, self::APP_SECRET);

        return $this->call('POST', '/webhooks/spx', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_APP_ID' => (string) self::APP_ID,
            'HTTP_CHECK_SIGN' => $sign,
            'HTTP_TIMESTAMP' => (string) $timestamp,
            'HTTP_RANDOM_NUM' => '55',
        ], $raw);
    }
}
