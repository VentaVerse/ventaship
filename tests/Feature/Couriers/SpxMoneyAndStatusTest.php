<?php

namespace Tests\Feature\Couriers;

use App\Couriers\Spx\SpxClient;
use App\Http\Resources\ShipmentResource;
use App\Models\Admin;
use App\Models\ClientApp;
use App\Models\CourierAccount;
use App\Models\OutgoingEvent;
use App\Models\Shipment;
use App\Models\WebhookReceipt;
use App\Shipments\Jobs\BookShipment;
use App\Shipments\Poller;
use App\Shipments\TrackingRefresher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsCourierAccounts;
use Tests\TestCase;

/**
 * SPX money and status, end to end through the real adapter with
 * only HTTP faked: the booking fee on every booking path, the actual fee from webhooks and 2.2, lost/damaged and
 * on-hold mapping, the create-failure reason, late-resolved booked data, and the proofs/driver/EDD kept in meta.
 */
class SpxMoneyAndStatusTest extends TestCase
{
    use BuildsCourierAccounts, RefreshDatabase;

    private const APP_ID = 424242;

    private const APP_SECRET = 'fake-app-secret-not-real';

    private const TRACKING = 'SPEPH00000001234B';

    private CourierAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->account = $this->spxAccount(['external_ref' => (string) self::APP_ID]);
    }

    // ---------------------------------------------------------------- fees on every booking path

    public function test_immediate_booking_stores_the_estimated_fee_and_the_api_answers_it(): void
    {
        Http::fake([
            '*batch_create_order*' => Http::response(['ret_code' => 0, 'message' => 'success.', 'data' => ['batch_no' => 1]]),
            '*get_order_create_result*' => Http::response($this->createResult()),
        ]);
        $s = $this->queuedShipment();

        Bus::dispatchSync(new BookShipment($s->id));

        $s->refresh();
        $this->assertSame('booked', $s->state);
        $this->assertSame('55.00', $s->fee_amount);
        $this->assertSame('PHP', $s->fee_currency);
        $this->assertSame('0.00', $s->meta['remote_area_fee']);
        $this->assertSame(['amount' => '55.00', 'currency' => 'PHP'], (new ShipmentResource($s))->toArray()['fee']);
        $this->assertSame('https://uat.spx.ph/track?'.self::TRACKING, (new ShipmentResource($s))->toArray()['tracking_url']);
    }

    public function test_poller_resolved_booking_stores_the_same_booked_data_as_the_immediate_path(): void
    {
        Http::fake(['*get_order_create_result*' => Http::response($this->createResult())]);
        $s = $this->creatingShipment();

        app(Poller::class)->run();

        $s->refresh();
        $this->assertSame('booked', $s->state);
        $this->assertSame(self::TRACKING, $s->tracking_number);
        $this->assertSame('55.00', $s->fee_amount);
        $this->assertSame('PHP', $s->fee_currency);
        $this->assertSame('S-01', $s->meta['booked_response']['r_first_sort_code']);
        $this->assertSame('55.00', $s->meta['estimated_shipping_fee']);
        $this->assertSame(['delivery' => 'https://uat.spx.ph/track?'.self::TRACKING], $s->meta['tracking_links']);
        $this->assertSame(9001, $s->meta['batch_no'], 'booking meta kept');
        $this->assertArrayNotHasKey('pending', $s->meta);
        $this->assertSame('https://uat.spx.ph/track?'.self::TRACKING, (new ShipmentResource($s))->toArray()['tracking_url']);
    }

    public function test_webhook_resolved_booking_asks_1_5_once_for_its_booked_data(): void
    {
        Http::fake(['*get_order_create_result*' => Http::response($this->createResult())]);
        $s = $this->creatingShipment();

        $this->postSigned($this->payload(['status_code' => '1001', 'order_id' => 'VS000042']))->assertOk();

        $s->refresh();
        $this->assertSame('booked', $s->state);
        $this->assertSame('55.00', $s->fee_amount);
        $this->assertSame('S-01', $s->meta['booked_response']['r_first_sort_code']);
        $this->assertSame('1001', $s->courier_status);
        Http::assertSentCount(1);
    }

    public function test_webhook_resolved_booking_still_books_when_1_5_cannot_answer(): void
    {
        Http::fake(['*get_order_create_result*' => Http::failedConnection()]);
        $s = $this->creatingShipment();

        $this->postSigned($this->payload(['status_code' => '1001', 'order_id' => 'VS000042']))->assertOk();

        $s->refresh();
        $this->assertSame('booked', $s->state);
        $this->assertSame(self::TRACKING, $s->tracking_number);
        $this->assertNull($s->fee_amount);
        // The webhook's own link is kept even so.
        $this->assertSame(['delivery' => 'https://uat.spx.ph/track?'.self::TRACKING], $s->meta['tracking_links']);
        $this->assertSame('processed', WebhookReceipt::query()->sole()->status);
    }

    // ---------------------------------------------------------------- actual fee

    public function test_webhook_actual_fee_latest_wins_and_never_falls_back_to_null_or_zero(): void
    {
        $s = $this->bookedShipment();

        $this->postSigned($this->payload(['status_code' => '2001', 'timestamp' => 1762570000, 'actual_shipping_fee' => 57.5, 'id' => 'a']))->assertOk();
        $this->assertSame('57.50', $s->refresh()->actual_fee);

        // An older event arriving late does not replace it.
        $this->postSigned($this->payload(['status_code' => '1001', 'timestamp' => 1762560000, 'actual_shipping_fee' => 40, 'id' => 'b']))->assertOk();
        $this->assertSame('57.50', $s->refresh()->actual_fee);

        // A newer one does.
        $this->postSigned($this->payload(['status_code' => '2006', 'timestamp' => 1762580000, 'actual_shipping_fee' => 60, 'id' => 'c']))->assertOk();
        $this->assertSame('60.00', $s->refresh()->actual_fee);

        // Absent (6003 sends none), null and zero never erase a real value.
        $this->postSigned($this->payload(['status_code' => '4001', 'timestamp' => 1762590000, 'id' => 'd']))->assertOk();
        $this->postSigned($this->payload(['status_code' => '4001', 'timestamp' => 1762600000, 'actual_shipping_fee' => null, 'id' => 'e']))->assertOk();
        $this->postSigned($this->payload(['status_code' => '4001', 'timestamp' => 1762610000, 'actual_shipping_fee' => 0, 'id' => 'f']))->assertOk();
        $this->assertSame('60.00', $s->refresh()->actual_fee);
        $this->assertSame('webhook', $s->meta['actual_fee_source']);

        // The booking fee the API reports is untouched.
        $this->assertSame('55.00', $s->fee_amount);
    }

    public function test_a_delivered_poll_stores_the_2_2_actual_fee_and_in_transit_polls_never_ask_2_2(): void
    {
        $s = $this->bookedShipment();
        Http::fakeSequence('*batch_search_order*')
            ->push($this->track('2001'))
            ->push($this->track('4001'));
        Http::fake(['*batch_get_asf*' => Http::response(['ret_code' => 0, 'message' => 'success.', 'data' => [
            'orders' => [['tracking_no' => self::TRACKING, 'fee_info' => ['actual_shipping_fee' => 62, 'return_shipping_fee' => 0]]],
            'fail_list' => [],
        ]])]);

        app(Poller::class)->run();
        $this->assertNull($s->refresh()->actual_fee);
        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), 'batch_get_asf'));

        Shipment::query()->whereKey($s->id)->toBase()->update(['next_poll_at' => null]);
        app(Poller::class)->run();

        $s->refresh();
        $this->assertSame('62.00', $s->actual_fee);
        $this->assertSame('poll', $s->meta['actual_fee_source']);
        $this->assertSame('delivered', $s->phase);
        $this->assertNotNull($s->terminal_at, 'delivered is final: the poller stops asking');
        $this->assertSame(['min' => 1, 'max' => 3, 'source' => 'tracking'], $s->meta['edd']);
        $this->assertSame(1, collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), 'batch_get_asf'))->count());
    }

    public function test_a_manual_refresh_of_a_delivered_parcel_asks_2_2_at_most_once_a_day(): void
    {
        $s = $this->bookedShipment(['courier_status' => '4001', 'phase' => 'delivered', 'terminal_at' => now()]);
        Http::fake([
            '*batch_search_order*' => Http::response($this->track('4001')),
            '*batch_get_asf*' => Http::response(['ret_code' => 0, 'message' => 'success.', 'data' => ['orders' => [], 'fail_list' => []]]),
        ]);
        $refresher = app(TrackingRefresher::class);

        $refresher->refresh($s);
        $this->travel(2)->minutes();
        $refresher->refresh($s->refresh());

        $this->assertSame(1, collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), 'batch_get_asf'))->count());
        $this->assertNull($s->refresh()->actual_fee, 'still unknown');
    }

    // ---------------------------------------------------------------- status mapping

    public function test_lost_and_damaged_are_lost_damaged_final_and_void_cod(): void
    {
        foreach (['5003' => 'VS000050', '5002' => 'VS000051'] as $code => $ref) {
            $s = $this->bookedShipment([
                'tracking_number' => "SPEPH{$code}", 'courier_ref' => $ref, 'payment_method' => 'cod',
                'payment_state' => 'cod_unpaid', 'cod_amount' => '1290.00',
            ]);

            $this->postSigned($this->payload(['status_code' => $code, 'tracking_no' => "SPEPH{$code}", 'id' => "lost-{$code}"]))->assertOk();

            $s->refresh();
            $this->assertSame('lost_damaged', $s->phase, $code);
            $this->assertSame('cod_void', $s->payment_state, $code);
            $this->assertNotNull($s->terminal_at, $code);
        }
        $this->assertSame(2, OutgoingEvent::query()->where('type', 'shipment.payment_state_changed')->count());
    }

    public function test_on_hold_is_attention_not_final_and_keeps_cod_unpaid(): void
    {
        $s = $this->bookedShipment(['payment_method' => 'cod', 'payment_state' => 'cod_unpaid', 'cod_amount' => '500.00']);

        $this->postSigned($this->payload(['status_code' => '3001', 'id' => 'hold']))->assertOk();

        $s->refresh();
        $this->assertSame('attention', $s->phase);
        $this->assertSame('On Hold', (new ShipmentResource($s))->toArray()['courier_status_label']);
        $this->assertSame('cod_unpaid', $s->payment_state);
        $this->assertNull($s->terminal_at);
    }

    // ---------------------------------------------------------------- failure reason

    public function test_a_refused_booking_shows_a_plain_reason_and_keeps_spx_words_in_meta(): void
    {
        Http::fake([
            '*batch_create_order*' => Http::response(['ret_code' => 0, 'message' => 'success.', 'data' => ['batch_no' => 1]]),
            '*get_order_create_result*' => Http::response(['ret_code' => 0, 'message' => 'success.', 'data' => [
                'batch_no' => 1, 'task_status' => 4, 'description' => 'Done', 'total_count' => 1, 'success_count' => 0,
                'fail_count' => 1, 'progress' => '100.00%', 'orders' => [],
                'fail_list' => [['order_id' => 'VS000042', 'message' => 'Create Order Failed.', 'debug_msg' => 'now_time over cutoff_time', 'ret_code' => 10080]],
            ]]),
        ]);
        $s = $this->queuedShipment();

        Bus::dispatchSync(new BookShipment($s->id));

        $s->refresh();
        $expected = 'Pickup time has passed. Choose another pickup time.';
        $this->assertSame('failed', $s->state);
        $this->assertSame($expected, $s->error_message);
        $this->assertSame('SPX 10080: Create Order Failed. | now_time over cutoff_time', $s->meta['courier_error']);
        $this->assertSame(['code' => 'courier_refused', 'message' => $expected], (new ShipmentResource($s))->toArray()['error']);

        $this->actingAs($this->admin());
        $this->get(route('shipments.show', $s->public_id))->assertOk()->assertSee($expected)->assertDontSee('now_time over cutoff_time');
    }

    public function test_a_late_refusal_found_by_the_poller_carries_the_reason_too(): void
    {
        Http::fake(['*get_order_create_result*' => Http::response(['ret_code' => 0, 'message' => 'success.', 'data' => [
            'batch_no' => 9001, 'task_status' => 4, 'description' => 'Done', 'fail_count' => 1, 'orders' => [],
            'fail_list' => [['message' => 'Create Order Failed.', 'debug_msg' => 'deliver address not in service area', 'ret_code' => 10019]],
        ]])]);
        $s = $this->creatingShipment();

        app(Poller::class)->run();

        $this->assertSame("SPX doesn't deliver here. Check the delivery address.", $s->refresh()->error_message);
        $this->assertSame('SPX 10019: Create Order Failed. | deliver address not in service area', $s->meta['courier_error']);
    }

    // ---------------------------------------------------------------- POD, driver, EDD, evidence

    public function test_tracking_webhook_keeps_pod_driver_and_edd_in_meta_and_the_page_links_the_photos(): void
    {
        $s = $this->bookedShipment();

        $this->postSigned($this->payload([
            'status_code' => '4001', 'id' => 'pod', 'actual_shipping_fee' => 55,
            'proof_of_delivery_list' => ['https://cdn.spx.ph/pod/1.jpg', 'https://cdn.spx.ph/pod/2.jpg'],
            'driver_name' => 'Juan', 'driver_phone_number' => '09170000000',
            'edd_min' => 2, 'edd_max' => 4,
        ]))->assertOk();

        $s->refresh();
        $this->assertSame(['epod' => ['https://cdn.spx.ph/pod/1.jpg', 'https://cdn.spx.ph/pod/2.jpg']], $s->meta['proofs']);
        $this->assertSame(['name' => 'Juan', 'phone' => '09170000000'], $s->meta['driver']);
        $this->assertSame(['min' => 2, 'max' => 4, 'source' => 'webhook'], $s->meta['edd']);
        $this->assertSame('S-01', $s->meta['booked_response']['r_first_sort_code'], 'earlier meta kept');
        $this->assertArrayNotHasKey('meta', $s->events()->sole()->raw, 'the driver is not copied into the event');
        $this->assertArrayNotHasKey('proofs', (new ShipmentResource($s))->toArray(), 'nothing new on the client API');

        $this->actingAs($this->admin());
        $this->get(route('shipments.show', $s->public_id))->assertOk()
            ->assertSee('Proof of delivery')
            ->assertSee('href="https://cdn.spx.ph/pod/2.jpg"', false)
            ->assertSee('Actual fee');
    }

    public function test_evidence_push_is_verified_and_its_proofs_kept_on_the_matching_shipment(): void
    {
        $s = $this->bookedShipment();
        $raw = json_encode([
            'tracking_no' => self::TRACKING, 'customer_order_id' => 'VS000042', 'id' => 'ep-1',
            'epod_list' => ['https://cdn.spx.ph/epod/a.jpg'], 'epop_list' => ['https://cdn.spx.ph/epop/b.jpg'],
            'tracking_code_name' => 'Delivered', 'status' => 'Delivered',
        ], JSON_UNESCAPED_SLASHES);

        // Forged first: answered 200, stored rejected, nothing kept.
        $this->postSigned($raw, sign: str_repeat('0', 64))->assertOk();
        $this->assertArrayNotHasKey('proofs', $s->refresh()->meta);

        $this->postSigned($raw)->assertOk();

        $s->refresh();
        $this->assertSame(['epod' => ['https://cdn.spx.ph/epod/a.jpg'], 'epop' => ['https://cdn.spx.ph/epop/b.jpg']], $s->meta['proofs']);
        $this->assertSame(['rejected', 'processed'], WebhookReceipt::query()->orderBy('id')->pluck('status')->all());
        $this->assertSame(0, $s->events()->count(), 'an evidence push is not a status');
        $this->assertSame(0, OutgoingEvent::query()->count());
    }

    public function test_evidence_push_for_a_parcel_we_do_not_have_is_unmatched(): void
    {
        $this->postSigned(json_encode(['tracking_no' => 'SPX-NOT-OURS', 'id' => 'ep-2', 'epod_list' => ['https://cdn.spx.ph/x.jpg']]))->assertOk();

        $this->assertSame('unmatched', WebhookReceipt::query()->sole()->status);
    }

    // ---------------------------------------------------------------- helpers

    private function admin(): Admin
    {
        return Admin::query()->create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'correct-horse-battery']);
    }

    /** @return array<string, mixed> An SPX 1.5 answer in the shape SPX actually sends */
    private function createResult(): array
    {
        return ['ret_code' => 0, 'message' => 'success.', 'data' => [
            'batch_no' => 9001, 'task_status' => 4, 'description' => 'Done', 'total_count' => 1, 'success_count' => 1,
            'fail_count' => 0, 'progress' => '100.00%',
            'orders' => [[
                'order_id' => 'VS000042', 'tracking_no' => self::TRACKING,
                'tracking_link' => 'https://uat.spx.ph/track?'.self::TRACKING,
                'estimated_shipping_fee' => 55, 'basic_shipping_fee' => 195, 'voucher_shipping_fee' => 140,
                'cod_service_fee' => 0, 'insurance_service_fee' => 0, 'vat_fee' => 0, 'remote_area_fee' => 0,
                'r_first_sort_code' => 'S-01', 'r_fifth_sort_code' => 'S-05',
            ]],
            'fail_list' => [],
        ]];
    }

    /** @return array<string, mixed> */
    private function track(string $status): array
    {
        return ['ret_code' => 0, 'message' => 'success.', 'data' => ['orders' => [[
            'tracking_no' => self::TRACKING, 'status_code' => $status,
            'tracking_link' => 'https://uat.spx.ph/track?'.self::TRACKING,
            'routes' => [['status_code' => $status, 'timestamp' => now()->getTimestamp() - 60]],
            'edd_info' => ['edd_min' => 1, 'edd_max' => 3],
        ]], 'fail_list' => []]];
    }

    /** @param  array<string, mixed>  $attributes */
    private function bookedShipment(array $attributes = []): Shipment
    {
        return Shipment::factory()->create($attributes + [
            'client_app_id' => ClientApp::factory(),
            'courier_account_id' => $this->account->id,
            'courier' => 'spx',
            'state' => 'booked',
            'tracking_number' => self::TRACKING,
            'courier_ref' => 'VS000042',
            'booked_at' => now()->subHour(),
            'fee_amount' => '55.00',
            'fee_currency' => 'PHP',
            'meta' => ['batch_no' => 9001, 'booked_response' => ['r_first_sort_code' => 'S-01']],
        ]);
    }

    private function creatingShipment(): Shipment
    {
        return $this->bookedShipment([
            'state' => 'booking', 'courier_status' => 'creating', 'tracking_number' => null, 'booked_at' => null,
            'fee_amount' => null, 'fee_currency' => null, 'meta' => ['batch_no' => 9001, 'pending' => true],
        ]);
    }

    private function queuedShipment(): Shipment
    {
        return Shipment::factory()->create([
            'client_app_id' => ClientApp::factory(),
            'courier_account_id' => $this->account->id,
            'courier' => 'spx',
            'client_reference' => '4211',
            'active_reference' => '4211',
            'courier_ref' => 'VS000042',
            'state' => 'queued',
            'method' => 'dropoff',
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
            'status' => 'In Transit',
            'status_code' => '2001',
            'message' => 'x',
            'tracking_no' => self::TRACKING,
            'tracking_link' => 'https://uat.spx.ph/track?'.self::TRACKING,
            'order_id' => 'VS000042',
            'timestamp' => 1762570000,
            'id' => 'd4dcfea1-5286-4921-8fd3-7ded133f8579',
        ], JSON_UNESCAPED_SLASHES);
    }

    private function postSigned(string $raw, ?string $sign = null): TestResponse
    {
        $timestamp = now()->getTimestamp();
        $sign ??= SpxClient::sign(self::APP_ID, $timestamp, 55, $raw, self::APP_SECRET);

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
