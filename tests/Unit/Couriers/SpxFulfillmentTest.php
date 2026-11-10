<?php

namespace Tests\Unit\Couriers;

use App\Couriers\AccountSettings;
use App\Couriers\CourierOrder;
use App\Couriers\PickupPoint;
use App\Couriers\Spx\SpxClient;
use App\Couriers\Spx\SpxFulfillment;
use App\Shipments\PhaseResolver;
use App\Models\CourierAccount;
use App\Models\Shipment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsCourierAccounts;
use Tests\TestCase;

/**
 * SpxFulfillment's booking, cancellation, tracking, label and fee methods, for one account. Ground truth for every
 * request/response shape is SPX's api.md and integration brief. Http::fake only, and tests/TestCase.php refuses
 * stray requests, so nothing here reaches SPX.
 *
 * A no-op sleeper is injected into every SpxFulfillment below so the bounded-poll/backoff logic (createShipment()'s
 * ~15s window, getTracking()'s 13251 retry) never actually sleeps.
 */
class SpxFulfillmentTest extends TestCase
{
    use BuildsCourierAccounts, RefreshDatabase;

    private ?CourierAccount $account = null;

    private function noSleep(): \Closure
    {
        return function (int $ms): void {
            // intentionally empty
        };
    }

    private function account(): CourierAccount
    {
        return $this->account ??= CourierAccount::factory()->create(['courier' => 'spx', 'mode' => 'sandbox']);
    }

    private function courier(): SpxFulfillment
    {
        $account = $this->account()->fresh();

        return new SpxFulfillment($account, AccountSettings::for($account), sleeper: $this->noSleep());
    }

    private function configureAccountCredentials(): void
    {
        $settings = AccountSettings::for($this->account());
        $settings->write('spx_sandbox_app_id', '424242');
        $settings->writeSecret('spx_sandbox_secret_key', 'fake-app-secret-not-real');
        $settings->write('spx_sandbox_user_id', '999999');
        $settings->writeSecret('spx_sandbox_user_secret', 'fake-user-secret-not-real');
    }

    /** The pickup origin the client sends with the shipment. */
    private function pickup(): PickupPoint
    {
        return PickupPoint::fromArray([
            'contact_name'  => 'Warehouse Team',
            'contact_phone' => '09171112222',
            'address_1'     => '456 Warehouse Ave',
            'barangay'      => 'Bel-Air',
            'city'          => 'Makati',
            'state'         => 'Metro Manila',
            'postcode'      => '1200',
            'country'       => 'PH',
        ]);
    }

    /** A Cebu (non-NCR) shipment on the SPX account. */
    private function makeShipment(array $recipient = [], array $attributes = []): Shipment
    {
        return Shipment::factory()->create($attributes + [
            'courier_account_id' => $this->account()->id,
            'courier' => 'spx',
            'courier_ref' => 'VS000042',
            'payment_method' => 'prepaid',
            'recipient' => $recipient + [
                'first_name' => 'Jane',
                'last_name' => 'Doe',
                'phone' => '+639171234567',
                'address_1' => '123 Mango St',
                'barangay' => 'Lahug (Pob.)',
                'city' => 'Cebu City',
                'state' => 'Cebu',
                'postcode' => '6000',
                'country' => 'PH',
            ],
            'items' => [],
            'total' => 1100,
        ]);
    }

    private function makeOrder(array $recipient = [], array $attributes = []): CourierOrder
    {
        return CourierOrder::fromApi($this->makeShipment($recipient, $attributes));
    }

    /** Booking options with the pickup point the client sent and the parcel weight typed. */
    private function opts(array $opts = []): array
    {
        return $opts + ['pickup' => $this->pickup(), 'weight_kg' => 1.2];
    }

    private function doneCreateResultResponse(string $trackingNo, int $batchNo = 207982800897461, array $extra = []): array
    {
        return [
            'ret_code' => 0, 'message' => 'success.',
            'data' => array_merge([
                'batch_no' => $batchNo, 'task_status' => 4, 'description' => 'Done',
                'total_count' => 1, 'success_count' => 1, 'fail_count' => 0, 'progress' => '100.00%',
                'orders' => [[
                    'order_id' => '1', 'tracking_no' => $trackingNo,
                    'tracking_link' => "https://test.spx.co.th/track?{$trackingNo}",
                    'estimated_shipping_fee' => 25,
                ]],
                'fail_list' => [],
            ], $extra),
        ];
    }

    // ------------------------------------------------------------------
    // createShipment — fast-complete path: payload shape + async resolution.
    // ------------------------------------------------------------------

    public function test_create_shipment_sends_the_documented_payload_shape_and_resolves_fast(): void
    {
        $this->configureAccountCredentials();

        Http::fake([
            '*batch_create_order*' => Http::response(['ret_code' => 0, 'message' => 'success.', 'data' => ['batch_no' => 207982800897461]]),
            '*get_order_create_result*' => Http::response($this->doneCreateResultResponse('SPEPH000000000001')),
        ]);

        $order = $this->makeOrder([], ['items' => [['name' => 'Guitar Strap', 'price' => 500, 'quantity' => 2]]]);

        $result = $this->courier()->createShipment($order, $this->opts([
            'service' => 'standard',
            'parcel'  => 'medium', // Str::slug('Medium')
            'shipping_payment' => 'sender_pay',
        ]));

        $this->assertTrue($result['success']);
        $this->assertSame('SPEPH000000000001', $result['tracking_number']);
        $this->assertNull($result['status']); // real status comes from the next tracking poll
        $this->assertSame(207982800897461, $result['meta']['batch_no']);

        Http::assertSent(function ($request) {
            if (!str_contains($request->url(), 'batch_create_order')) {
                return false;
            }
            $body = $request->data();
            $o = $body['orders'][0];

            return $body['user_id'] === 999999
                && $body['user_secret'] === 'fake-user-secret-not-real'
                && $o['order_id'] === 'VS000042'
                && $o['base_info']['service_type'] === 1
                // sender_info from the pickup point, placed on SPX's location
                // list: state = island group, city = province, district =
                // city, street = barangay, each as SPX spells it;
                // *_detail_address <- the actual address text.
                && $o['sender_info']['sender_state'] === 'Metro Manila'
                && $o['sender_info']['sender_city'] === 'Metro Manila'
                && $o['sender_info']['sender_district'] === 'Makati City'
                && $o['sender_info']['sender_street'] === 'Bel-air'
                && $o['sender_info']['sender_detail_address'] === '456 Warehouse Ave'
                && $o['sender_info']['sender_post_code'] === '1200'
                // Already valid PH format (11 digits, starts with 0) -> passes through unchanged.
                && $o['sender_info']['sender_phone'] === '09171112222'
                // deliver_info from the order's own shipping address, placed
                // the same way; detail_address <- address_1.
                && $o['deliver_info']['deliver_state'] === 'Visayas'
                && $o['deliver_info']['deliver_city'] === 'Cebu'
                && $o['deliver_info']['deliver_district'] === 'Cebu City'
                && $o['deliver_info']['deliver_street'] === 'Lahug (Pob.)'
                && $o['deliver_info']['deliver_detail_address'] === '123 Mango St'
                && $o['deliver_info']['deliver_post_code'] === '6000'
                && $o['deliver_info']['deliver_name'] === 'Jane Doe'
                // "+639171234567" stripped of non-digits -> 12 digits starting with 63 -> passes through as-is.
                && $o['deliver_info']['deliver_phone'] === '639171234567'
                // deliver_instruction carries the buyer's message to the courier;
                // this order has none -> omitted entirely, never a JSON null.
                && !array_key_exists('deliver_instruction', $o['deliver_info'])
                // fulfillment_info: sender_pay -> payment_role 1; not COD -> cod_collection 0; no pickup slot -> drop-off.
                && $o['fulfillment_info']['payment_role'] === 1
                && $o['fulfillment_info']['cod_collection'] === 0
                && !array_key_exists('cod_amount', $o['fulfillment_info'])
                && $o['fulfillment_info']['collect_type'] === 2
                // parcel_info dims come from the "Medium" pouch preset (35x25x15cm); the weight is the typed one.
                && (float) $o['parcel_info']['parcel_length'] === 35.0
                && (float) $o['parcel_info']['parcel_width'] === 25.0
                && (float) $o['parcel_info']['parcel_height'] === 15.0
                && (float) $o['parcel_info']['parcel_weight'] === 1.2
                && (int) $o['parcel_info']['express_insured_value'] === 0
                && $o['parcel_info']['parcel_item_type'] === 'General merchandise';
        });
    }

    // ------------------------------------------------------------------
    // Phone normalization (T2 review, CRITICAL 1).
    // ------------------------------------------------------------------

    public function test_create_shipment_normalizes_a_bare_10_digit_mobile_number(): void
    {
        $this->configureAccountCredentials();

        Http::fake([
            '*batch_create_order*' => Http::response(['ret_code' => 0, 'message' => 'success.', 'data' => ['batch_no' => 1]]),
            '*get_order_create_result*' => Http::response($this->doneCreateResultResponse('SPEPH000000000010', 1)),
        ]);

        // Real dev-DB shape: 10 digits, no leading 0.
        $order = $this->makeOrder(['phone' => '9171234567']);

        $result = $this->courier()->createShipment($order, $this->opts(['parcel' => 'small', 'shipping_payment' => 'sender_pay']));

        $this->assertTrue($result['success']);
        Http::assertSent(function ($request) {
            if (!str_contains($request->url(), 'batch_create_order')) {
                return false;
            }
            return $request->data()['orders'][0]['deliver_info']['deliver_phone'] === '09171234567';
        });
    }

    public function test_create_shipment_fails_clearly_on_an_empty_or_unresolvable_phone(): void
    {
        $this->configureAccountCredentials();
        Http::fake();

        $order = $this->makeOrder(['phone' => '']);
        $result = $this->courier()->createShipment($order, $this->opts(['parcel' => 'small', 'shipping_payment' => 'sender_pay']));

        $this->assertFalse($result['success']);
        $this->assertSame("The customer's phone isn't a valid PH mobile number.", $result['error']);
        Http::assertNothingSent();
    }

    public function test_create_shipment_maps_cod_and_receiver_pay_and_insure(): void
    {
        $this->configureAccountCredentials();

        Http::fake([
            '*batch_create_order*' => Http::response(['ret_code' => 0, 'message' => 'success.', 'data' => ['batch_no' => 1]]),
            '*get_order_create_result*' => Http::response($this->doneCreateResultResponse('SPEPH000000000002', 1)),
        ]);

        $order = $this->makeOrder([], ['payment_method' => 'cod', 'cod_amount' => 1500, 'total' => 1500]);

        $this->courier()->createShipment($order, $this->opts([
            'parcel' => 'small',
            'shipping_payment' => 'receiver_pay',
            'insure' => true,
        ]));

        Http::assertSent(function ($request) {
            if (!str_contains($request->url(), 'batch_create_order')) {
                return false;
            }
            $o = $request->data()['orders'][0];
            return $o['fulfillment_info']['payment_role'] === 2
                && $o['fulfillment_info']['cod_collection'] === 1
                && $o['fulfillment_info']['cod_amount'] === 1500
                && (float) $o['parcel_info']['express_insured_value'] === 1500.0;
        });
    }

    public function test_create_shipment_uses_custom_dimensions_when_parcel_is_custom(): void
    {
        $this->configureAccountCredentials();

        Http::fake([
            '*batch_create_order*' => Http::response(['ret_code' => 0, 'message' => 'success.', 'data' => ['batch_no' => 1]]),
            '*get_order_create_result*' => Http::response($this->doneCreateResultResponse('SPEPH000000000003', 1)),
        ]);

        $order = $this->makeOrder();

        $result = $this->courier()->createShipment($order, $this->opts([
            'parcel' => 'custom', 'length_cm' => 60, 'width_cm' => 40, 'height_cm' => 30, 'weight_kg' => 12,
            'shipping_payment' => 'sender_pay',
        ]));

        $this->assertTrue($result['success']);
        Http::assertSent(function ($request) {
            if (!str_contains($request->url(), 'batch_create_order')) {
                return false;
            }
            $p = $request->data()['orders'][0]['parcel_info'];
            return (float) $p['parcel_length'] === 60.0 && (float) $p['parcel_weight'] === 12.0;
        });
    }

    public function test_create_shipment_rejects_custom_parcel_with_missing_dimensions_and_never_calls_spx(): void
    {
        $this->configureAccountCredentials();
        Http::fake();

        $order = $this->makeOrder();
        $result = $this->courier()->createShipment($order, $this->opts(['parcel' => 'custom', 'shipping_payment' => 'sender_pay']));

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Enter the parcel size.', $result['error']);
        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------
    // createShipment — credential + address guards.
    // ------------------------------------------------------------------

    public function test_create_shipment_fails_clearly_without_user_credentials_and_never_calls_spx(): void
    {
        // App-level creds only (isConfigured true), no user_id/user_secret.
        AccountSettings::for($this->account())->write('spx_sandbox_app_id', '424242');
        AccountSettings::for($this->account())->writeSecret('spx_sandbox_secret_key', 'x');
        Http::fake();

        $order = $this->makeOrder();
        $result = $this->courier()->createShipment($order, $this->opts(['parcel' => 'small', 'shipping_payment' => 'sender_pay']));

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('User ID / User Secret', $result['error']);
        Http::assertNothingSent();
    }

    public function test_create_shipment_fails_clearly_when_pickup_origin_is_incomplete(): void
    {
        $this->configureAccountCredentials();
        // No pickup point sent with the shipment at all.
        Http::fake();

        $order = $this->makeOrder();
        $result = $this->courier()->createShipment($order, ['parcel' => 'small', 'shipping_payment' => 'sender_pay']);

        $this->assertFalse($result['success']);
        $this->assertSame("The sender's province is missing.", $result['error']);
        Http::assertNothingSent();
    }

    public function test_create_shipment_fails_clearly_when_order_address_is_incomplete(): void
    {
        $this->configureAccountCredentials();
        Http::fake();

        $order = $this->makeOrder(['city' => '', 'state' => '']);
        $result = $this->courier()->createShipment($order, $this->opts(['parcel' => 'small', 'shipping_payment' => 'sender_pay']));

        $this->assertFalse($result['success']);
        $this->assertSame("The customer's province is missing.", $result['error']);
        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------
    // Missing postcode filled from PSGC; specific refusals; coverage code.
    // ------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function binRecipient(string $barangay, string $postcode = ''): array
    {
        return ['barangay' => $barangay, 'city' => 'Bangued (Capital)', 'state' => 'Abra', 'postcode' => $postcode, 'codes' => []];
    }

    public function test_the_creator_fills_a_missing_postcode_from_the_psgc_city(): void
    {
        $r = \App\Shipments\ShipmentCreator::attributes(['recipient' => $this->binRecipient('Agtangao')])['recipient'];
        $this->assertSame('2805', $r['postcode']);

        $p = \App\Shipments\ShipmentCreator::attributes(['pickup' => $this->binRecipient('Agtangao') + ['contact_phone' => '09171112222']])['pickup'];
        $this->assertSame('2805', $p['postcode']);

        $given = \App\Shipments\ShipmentCreator::attributes(['recipient' => $this->binRecipient('Agtangao', '9999')])['recipient'];
        $this->assertSame('9999', $given['postcode']);

        $unknown = \App\Shipments\ShipmentCreator::attributes(['recipient' => ['city' => 'Atlantis', 'state' => 'Abra']])['recipient'];
        $this->assertEmpty($unknown['postcode']);
    }

    public function test_booking_an_unlisted_barangay_without_postcode_is_refused_naming_it_and_sends_nothing(): void
    {
        $this->configureAccountCredentials();
        Http::fake();

        $order = $this->makeOrder($this->binRecipient('Agtangao'));
        $result = $this->courier()->createShipment($order, $this->opts(['parcel' => 'small', 'shipping_payment' => 'sender_pay']));

        $this->assertFalse($result['success']);
        $this->assertSame("SPX doesn't list the customer's barangay.", $result['error']);
        Http::assertNothingSent();
    }

    public function test_a_listed_barangay_without_postcode_books_with_the_psgc_postcode(): void
    {
        $this->configureAccountCredentials();
        Http::fake([
            '*batch_create_order*' => Http::response(['ret_code' => 0, 'message' => 'success.', 'data' => ['batch_no' => 1]]),
            '*get_order_create_result*' => Http::response($this->doneCreateResultResponse('SPEPH000000000002')),
        ]);

        $order = $this->makeOrder($this->binRecipient('Zone 4 Pob. (Town Proper)'));
        $result = $this->courier()->createShipment($order, $this->opts(['parcel' => 'small', 'shipping_payment' => 'sender_pay']));

        $this->assertTrue($result['success']);
        Http::assertSent(function ($request) {
            if (!str_contains($request->url(), 'batch_create_order')) {
                return false;
            }
            $d = $request->data()['orders'][0]['deliver_info'];

            return $d['deliver_post_code'] === '2805'
                && $d['deliver_state'] === 'North Luzon'
                && $d['deliver_city'] === 'Abra'
                && $d['deliver_district'] === 'Bangued'
                && $d['deliver_street'] === 'Zone 4 Pob. (Town Proper)';
        });
    }

    public function test_missing_fields_are_named_by_side_and_first_missing_wins(): void
    {
        $this->configureAccountCredentials();
        Http::fake();

        foreach ([
            [['barangay' => ''], "The customer's barangay is missing."],
            [['city' => '', 'barangay' => ''], "The customer's city is missing."],
            [['city' => 'Atlantis'], "The customer's postcode is missing."],
        ] as [$over, $message]) {
            $order = $this->makeOrder($over + ['postcode' => '', 'state' => 'Abra', 'city' => 'Bangued (Capital)', 'barangay' => 'Agtangao']);
            $result = $this->courier()->createShipment($order, $this->opts(['parcel' => 'small', 'shipping_payment' => 'sender_pay']));
            $this->assertSame($message, $result['error']);
        }

        $pickup = PickupPoint::fromArray([
            'contact_name' => 'W', 'contact_phone' => '09171112222', 'address_1' => '1 St',
            'barangay' => 'Bel-Air', 'city' => 'Atlantis', 'state' => 'Metro Manila', 'postcode' => '',
        ]);
        $result = $this->courier()->createShipment($this->makeOrder(), ['pickup' => $pickup, 'weight_kg' => 1] + ['parcel' => 'small']);
        $this->assertSame("The sender's postcode is missing.", $result['error']);
        Http::assertNothingSent();
    }

    public function test_coverage_for_an_unlisted_barangay_is_not_serviceable_with_a_code_and_no_http_call(): void
    {
        $this->configureAccountCredentials();
        Http::fake();

        $verdict = $this->courier()->checkServiceability($this->makeOrder($this->binRecipient('Agtangao')), $this->pickup());

        $this->assertSame('not_serviceable', $verdict->status);
        $this->assertSame('destination', $verdict->kind);
        $this->assertSame('spx_unlisted_barangay', $verdict->code);
        Http::assertNothingSent();
    }

    public function test_coverage_with_data_we_cannot_fill_is_unknown_with_a_code(): void
    {
        $this->configureAccountCredentials();
        Http::fake();

        $verdict = $this->courier()->checkServiceability($this->makeOrder(['city' => 'Atlantis', 'postcode' => '', 'barangay' => 'X']), $this->pickup());

        $this->assertSame('unknown', $verdict->status);
        $this->assertSame('missing_postcode', $verdict->code);
        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------
    // createShipment — async pending path (bounded poll never completes).
    // ------------------------------------------------------------------

    public function test_create_shipment_persists_creating_status_when_still_pending_after_bounded_poll(): void
    {
        $this->configureAccountCredentials();

        Http::fake([
            '*batch_create_order*' => Http::response(['ret_code' => 0, 'message' => 'success.', 'data' => ['batch_no' => 999]]),
            '*get_order_create_result*' => Http::response([
                'ret_code' => 0, 'message' => 'success.',
                'data' => ['batch_no' => 999, 'task_status' => 3, 'description' => 'Running', 'total_count' => 1, 'success_count' => 0, 'fail_count' => 0, 'progress' => '0%', 'orders' => [], 'fail_list' => []],
            ]),
        ]);

        $order = $this->makeOrder();
        $result = $this->courier()->createShipment($order, $this->opts(['parcel' => 'small', 'shipping_payment' => 'sender_pay']));

        $this->assertTrue($result['success']);
        $this->assertNull($result['tracking_number']);
        $this->assertSame('creating', $result['status']);
        $this->assertSame(999, $result['meta']['batch_no']);
        $this->assertTrue($result['meta']['pending']);

        // 1 create call + 6 bounded polls (immediate + 5 backoff retries).
        Http::assertSentCount(7);
    }

    public function test_create_shipment_reports_a_failed_async_creation_via_fail_list_not_task_status_alone(): void
    {
        $this->configureAccountCredentials();

        Http::fake([
            '*batch_create_order*' => Http::response(['ret_code' => 0, 'message' => 'success.', 'data' => ['batch_no' => 5]]),
            '*get_order_create_result*' => Http::response([
                'ret_code' => 0, 'message' => 'success.',
                'data' => [
                    'batch_no' => 5, 'task_status' => 4, 'description' => 'Done',
                    'total_count' => 1, 'success_count' => 0, 'fail_count' => 1, 'progress' => '100.00%',
                    'orders' => [],
                    'fail_list' => [['order_id' => '1', 'message' => 'Address invalid.', 'debug_msg' => 'x', 'ret_code' => 10018]],
                ],
            ]),
        ]);

        $order = $this->makeOrder();
        $result = $this->courier()->createShipment($order, $this->opts(['parcel' => 'small', 'shipping_payment' => 'sender_pay']));

        $this->assertFalse($result['success']);
        // Our sentence for the code first, then SPX's code and specific reason.
        $this->assertSame("SPX doesn't serve the pickup address. (SPX 10018: x)", $result['error']);
    }

    /**
     * T2 review, IMPORTANT 3: task_status 5 (Failed) is a SETTLED outcome,
     * exactly as final as 4 (Done) — polling further gains nothing.
     * pollCreateResult() must stop at the FIRST poll that returns it
     * (asserted via the exact HTTP call count: 1 create + 1 poll, not the
     * full bounded 6), and createShipment() must report it as a failure
     * immediately rather than persisting 'creating' and waiting out the
     * rest of the ~15s window for a batch that will never become Done.
     */
    public function test_create_shipment_stops_polling_immediately_on_task_status_5_failed(): void
    {
        $this->configureAccountCredentials();

        Http::fake([
            '*batch_create_order*' => Http::response(['ret_code' => 0, 'message' => 'success.', 'data' => ['batch_no' => 9]]),
            '*get_order_create_result*' => Http::response([
                'ret_code' => 0, 'message' => 'success.',
                'data' => [
                    'batch_no' => 9, 'task_status' => 5, 'description' => 'Failed',
                    'total_count' => 1, 'success_count' => 0, 'fail_count' => 0, 'progress' => '0%',
                    'orders' => [], 'fail_list' => [],
                ],
            ]),
        ]);

        $order = $this->makeOrder();
        $result = $this->courier()->createShipment($order, $this->opts(['parcel' => 'small', 'shipping_payment' => 'sender_pay']));

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Failed', $result['error']);
        // 1 create + exactly 1 poll — proves it stopped immediately rather
        // than exhausting the full bounded backoff window.
        Http::assertSentCount(2);
    }

    // ------------------------------------------------------------------
    // resolvePendingCreation — the courier:poll-status pending-creation pass.
    // ------------------------------------------------------------------

    public function test_resolve_pending_creation_returns_the_tracking_number_once_done(): void
    {
        $this->configureAccountCredentials();

        Http::fake([
            '*get_order_create_result*' => Http::response($this->doneCreateResultResponse('SPEPH000000000009', 42)),
        ]);

        $order = $this->makeOrder([], ['state' => 'booking', 'courier_status' => 'creating', 'meta' => ['batch_no' => 42]]);

        $answer = $this->courier()->resolvePendingCreation($order);

        $this->assertSame('SPEPH000000000009', $answer['tracking_number']);
        $this->assertSame('25.00', $answer['fee']);
        $this->assertSame('SPEPH000000000009', $answer['meta']['booked_response']['tracking_no']);
        $this->assertSame('25.00', $answer['meta']['estimated_shipping_fee']);
        $this->assertSame(['delivery' => 'https://test.spx.co.th/track?SPEPH000000000009'], $answer['meta']['tracking_links']);
    }

    public function test_resolve_pending_creation_returns_null_while_still_running(): void
    {
        $this->configureAccountCredentials();

        Http::fake([
            '*get_order_create_result*' => Http::response([
                'ret_code' => 0, 'message' => 'success.',
                'data' => ['batch_no' => 42, 'task_status' => 2, 'description' => 'Waiting', 'total_count' => 1, 'success_count' => 0, 'fail_count' => 0, 'progress' => '0%', 'orders' => [], 'fail_list' => []],
            ]),
        ]);

        $order = $this->makeOrder([], ['state' => 'booking', 'courier_status' => 'creating', 'meta' => ['batch_no' => 42]]);

        $this->assertNull($this->courier()->resolvePendingCreation($order));
    }

    public function test_resolve_pending_creation_records_a_permanent_failure_and_stops_being_creating(): void
    {
        $this->configureAccountCredentials();

        Http::fake([
            '*get_order_create_result*' => Http::response([
                'ret_code' => 0, 'message' => 'success.',
                'data' => [
                    'batch_no' => 7, 'task_status' => 4, 'description' => 'Done',
                    'total_count' => 1, 'success_count' => 0, 'fail_count' => 1, 'progress' => '100%',
                    'orders' => [], 'fail_list' => [['order_id' => '1', 'message' => 'Weight exceeds limit.', 'ret_code' => 10025]],
                ],
            ]),
        ]);

        $order = $this->makeOrder([], ['state' => 'booking', 'courier_status' => 'creating', 'meta' => ['batch_no' => 7]]);

        // The courier writes nothing: the caller records the failure from the answer.
        $answer = $this->courier()->resolvePendingCreation($order);

        $this->assertSame('creation_failed', $answer['courier_status']);
        $this->assertArrayNotHasKey('tracking_number', $answer);
        $this->assertTrue($answer['meta']['creation_failed']);
        $this->assertSame('Weight exceeds limit. (SPX 10025)', $answer['meta']['creation_error']);
        $this->assertSame(7, $answer['meta']['batch_no']);
    }

    /**
     * T2 review, IMPORTANT 3: without recognizing task_status 5 as settled,
     * this shipment would stay in courier_status='creating' forever, and
     * every scheduled poll would re-poll a batch that will never become Done.
     */
    public function test_resolve_pending_creation_treats_task_status_5_as_a_permanent_failure(): void
    {
        $this->configureAccountCredentials();

        Http::fake([
            '*get_order_create_result*' => Http::response([
                'ret_code' => 0, 'message' => 'success.',
                'data' => [
                    'batch_no' => 11, 'task_status' => 5, 'description' => 'Failed',
                    'total_count' => 1, 'success_count' => 0, 'fail_count' => 0, 'progress' => '0%',
                    'orders' => [], 'fail_list' => [],
                ],
            ]),
        ]);

        $order = $this->makeOrder([], ['state' => 'booking', 'courier_status' => 'creating', 'meta' => ['batch_no' => 11]]);

        $answer = $this->courier()->resolvePendingCreation($order);

        $this->assertSame('creation_failed', $answer['courier_status']);
        $this->assertTrue($answer['meta']['creation_failed']);
        $this->assertStringContainsString('Failed', $answer['meta']['creation_error']);
    }

    // ------------------------------------------------------------------
    // cancelShipment.
    // ------------------------------------------------------------------

    public function test_cancel_shipment_succeeds(): void
    {
        $this->configureAccountCredentials();
        Http::fake(['*batch_cancel_order*' => Http::response([
            'ret_code' => 0, 'message' => 'success.',
            'data' => ['tracking_no_list' => ['SPEPH1'], 'fail_list' => []],
        ])]);

        $result = $this->courier()->cancelShipment('SPEPH1');
        $this->assertTrue($result['success']);

        Http::assertSent(fn ($r) => $r->data()['tracking_no_list'] === ['SPEPH1']);
    }

    public function test_cancel_shipment_gives_a_friendly_message_on_13002_thrown_at_the_top_level(): void
    {
        $this->configureAccountCredentials();
        Http::fake(['*batch_cancel_order*' => Http::response(['ret_code' => 13002, 'message' => 'OrderUnableCancelCode', 'data' => null])]);

        $result = $this->courier()->cancelShipment('SPEPH1');
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('already been picked up', $result['error']);
    }

    public function test_cancel_shipment_gives_a_friendly_message_on_13002_inside_fail_list(): void
    {
        $this->configureAccountCredentials();
        Http::fake(['*batch_cancel_order*' => Http::response([
            'ret_code' => 0, 'message' => 'success.',
            'data' => ['tracking_no_list' => [], 'fail_list' => [['ret_code' => 13002, 'message' => 'Cancel Order Failed.', 'tracking_no' => 'SPEPH1']]],
        ])]);

        $result = $this->courier()->cancelShipment('SPEPH1');
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('already been picked up', $result['error']);
    }

    // ------------------------------------------------------------------
    // getTracking.
    // ------------------------------------------------------------------

    private function trackOrderResponse(string $trackingNo, array $overrides = []): array
    {
        return [
            'ret_code' => 0, 'message' => 'success.',
            'data' => ['orders' => [array_merge([
                'tracking_no' => $trackingNo,
                'status' => 'Pending Pickup',
                'status_code' => '1001',
                'tracking_link' => "https://uat.spx.ph/track?{$trackingNo}",
                'routes' => [
                    ['status' => 'Pending Pickup', 'status_code' => '1001', 'message' => 'x', 'timestamp' => 1753171999],
                ],
            ], $overrides)], 'fail_list' => []],
        ];
    }

    public function test_get_tracking_succeeds_and_maps_the_timeline(): void
    {
        $this->configureAccountCredentials();
        Http::fake([
            '*batch_search_order*' => Http::response($this->trackOrderResponse('SPEPH1')),
            '*batch_get_asf*' => Http::response([
                'ret_code' => 0, 'message' => 'success.',
                'data' => ['orders' => [['tracking_no' => 'SPEPH1', 'fee_info' => ['actual_shipping_fee' => 30]]], 'fail_list' => []],
            ]),
        ]);

        $result = $this->courier()->getTracking('SPEPH1');

        $this->assertTrue($result['success']);
        $this->assertSame('1001', $result['status']);
        $this->assertSame(['delivery' => 'https://uat.spx.ph/track?SPEPH1'], $result['links']);
        $this->assertSame([['status' => '1001', 'at' => 1753171999]], $result['timeline']);
        // Not delivered yet: 2.2 is not asked (finding #19).
        $this->assertNull($result['fee_info']);
        $this->assertNull($result['actual_fee']);
        $this->assertSame(['tracking_links' => ['delivery' => 'https://uat.spx.ph/track?SPEPH1']], $result['meta']);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'batch_get_asf'));
    }

    public function test_get_tracking_asks_2_2_once_delivered_at_most_once_a_day(): void
    {
        $this->configureAccountCredentials();
        Http::fake([
            '*batch_search_order*' => Http::response($this->trackOrderResponse('SPEPH1', [
                'status' => 'Delivered', 'status_code' => '4001',
                'edd_info' => ['edd_min' => 1, 'edd_max' => 3],
            ])),
            '*batch_get_asf*' => Http::response([
                'ret_code' => 0, 'message' => 'success.',
                'data' => ['orders' => [['tracking_no' => 'SPEPH1', 'fee_info' => ['actual_shipping_fee' => 57.5, 'return_shipping_fee' => 0]]], 'fail_list' => []],
            ]),
        ]);

        $first = $this->courier()->getTracking('SPEPH1');
        $second = $this->courier()->getTracking('SPEPH1');

        $this->assertSame('57.50', $first['actual_fee']);
        $this->assertSame(['min' => 1, 'max' => 3, 'source' => 'tracking'], $first['meta']['edd']);
        $this->assertArrayNotHasKey('actual_return_fee', $first['meta'], 'a zero return fee is SPX\'s "none"');
        $this->assertNull($second['actual_fee']);
        Http::assertSentCount(3); // two tracks, one fee lookup

        $this->travel(25)->hours();
        $this->courier()->getTracking('SPEPH1');
        Http::assertSentCount(5); // a day later it may ask again
    }

    public function test_get_tracking_asks_2_2_for_a_returned_parcel_but_not_for_lost_or_in_transit(): void
    {
        $this->configureAccountCredentials();
        Http::fakeSequence('*batch_search_order*')
            ->push($this->trackOrderResponse('SPEPH1', ['status_code' => '2001']))
            ->push($this->trackOrderResponse('SPEPH1', ['status_code' => '5003']))
            ->push($this->trackOrderResponse('SPEPH1', ['status_code' => '6003']));
        Http::fake(['*batch_get_asf*' => Http::response([
            'ret_code' => 0, 'message' => 'success.',
            'data' => ['orders' => [['tracking_no' => 'SPEPH1', 'fee_info' => ['actual_shipping_fee' => 40, 'return_shipping_fee' => 35]]], 'fail_list' => []],
        ])]);

        $this->courier()->getTracking('SPEPH1');
        $this->courier()->getTracking('SPEPH1');
        $returned = $this->courier()->getTracking('SPEPH1');

        $this->assertSame('40.00', $returned['actual_fee']);
        $this->assertSame('35.00', $returned['meta']['actual_return_fee']);
        $this->assertSame(1, collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), 'batch_get_asf'))->count());
    }

    public function test_get_tracking_retries_on_13251_then_succeeds(): void
    {
        $this->configureAccountCredentials();
        Http::fakeSequence('*batch_search_order*')
            ->push(['ret_code' => 13251, 'message' => 'order id cannot be found', 'data' => null])
            ->push($this->trackOrderResponse('SPEPH1'));
        Http::fake(['*batch_get_asf*' => Http::response(['ret_code' => 0, 'message' => 'success.', 'data' => ['orders' => [], 'fail_list' => []]])]);

        $result = $this->courier()->getTracking('SPEPH1');

        $this->assertTrue($result['success']);
        $this->assertSame('1001', $result['status']);
    }

    public function test_get_tracking_retries_on_13251_inside_fail_list_then_succeeds(): void
    {
        $this->configureAccountCredentials();
        Http::fakeSequence('*batch_search_order*')
            ->push(['ret_code' => 0, 'message' => 'success.', 'data' => ['orders' => [], 'fail_list' => [['ret_code' => 13251, 'message' => 'not found yet', 'tracking_no' => 'SPEPH1']]]])
            ->push($this->trackOrderResponse('SPEPH1'));
        Http::fake(['*batch_get_asf*' => Http::response(['ret_code' => 0, 'message' => 'success.', 'data' => ['orders' => [], 'fail_list' => []]])]);

        $result = $this->courier()->getTracking('SPEPH1');
        $this->assertTrue($result['success']);
    }

    // ------------------------------------------------------------------
    // statusPhase — every code in api.md's order status table.
    // ------------------------------------------------------------------

    public static function statusPhaseProvider(): array
    {
        return [
            'unset status (fresh booking)' => [null, 'to_handover'],
            'async create pending'  => ['creating', 'booking'],
            'async create failed'   => ['creation_failed', 'failed'],
            '1001 Pending Pickup'   => ['1001', 'to_handover'],
            '2001 In Transit'       => ['2001', 'in_transit'],
            '2006 Delivering'       => ['2006', 'in_transit'],
            '3001 On Hold'          => ['3001', 'attention'],
            '4001 Delivered'        => ['4001', 'delivered'],
            '5001 Pickup Failed'    => ['5001', 'failed'],
            '5002 Damaged'          => ['5002', 'lost_damaged'],
            '5003 Lost'             => ['5003', 'lost_damaged'],
            '6001 Returning'        => ['6001', 'returning'],
            '6002 Return Failed'    => ['6002', 'failed'],
            '6003 Returned'         => ['6003', 'returning'],
            '7001 Cancelled'        => ['7001', 'cancelled'],
            // Final-review fix (Finding 1): the literal statuses the admin
            // Cancel action stamps (the shared cancel writes
            // 'canceled' for every courier) bucket to cancelled too — an
            // admin-cancelled SPX order must not read as 'to_handover'
            // until the next poll overwrites it with 7001.
            'admin cancel literal'  => ['canceled', 'cancelled'],
            'admin cancel alt spelling' => ['cancelled', 'cancelled'],
        ];
    }

    #[DataProvider('statusPhaseProvider')]
    public function test_every_status_code_lands_in_exactly_one_spine_bucket(?string $status, string $expectedPhase): void
    {
        $result = $this->courier()->statusPhase($status);

        $this->assertSame($expectedPhase, $result['phase']);
        $this->assertContains($result['phase'], PhaseResolver::SPINE);
        $this->assertArrayHasKey('step', $result);
        $this->assertArrayHasKey('failed', $result);
    }

    public function test_terminal_and_cancelable_statuses_are_consistent_with_the_phase_bucketing(): void
    {
        $courier = $this->courier();

        foreach ($courier->terminalStatuses() as $status) {
            $this->assertContains($courier->statusPhase($status)['phase'], ['delivered', 'cancelled', 'returning', 'lost_damaged']);
        }
        // On Hold, Pickup Failed and Return Failed are retried by SPX: never final.
        foreach (['3001', '5001', '6002'] as $status) {
            $this->assertNotContains($status, $courier->terminalStatuses());
        }

        $this->assertSame(['1001'], $courier->cancelableStatuses());
    }

    // ------------------------------------------------------------------
    // estimateFee / getPickupSlots.
    // ------------------------------------------------------------------

    public function test_estimate_fee_returns_the_documented_shape(): void
    {
        $this->configureAccountCredentials();
        Http::fake(['*batch_check_order*' => Http::response([
            'ret_code' => 0, 'message' => 'success.',
            'data' => ['orders' => [['order_id' => '1', 'estimated_shipping_fee' => 23.2, 'basic_shipping_fee' => 29]], 'fail_list' => []],
        ])]);

        $fee = $this->courier()->estimateFee($this->makeOrder(), $this->opts(['parcel' => 'small', 'shipping_payment' => 'sender_pay']));

        $this->assertNotNull($fee);
        $this->assertSame(23.2, $fee->amount);
        $this->assertSame('PHP', $fee->currency);
        $this->assertIsString($fee->label);
    }

    public function test_estimate_fee_returns_null_when_the_pickup_origin_is_incomplete(): void
    {
        $this->configureAccountCredentials();
        // No pickup point: never even calls SPX for an estimate it can't build.
        Http::fake();

        $fee = $this->courier()->estimateFee($this->makeOrder(), ['parcel' => 'small', 'shipping_payment' => 'sender_pay']);

        $this->assertNull($fee);
        Http::assertNothingSent();
    }

    /** Every window SPX offers is an option, "Now" first within its day, labelled with the day and SPX's window. */
    public function test_get_pickup_slots_offers_every_window_with_now_first(): void
    {
        $this->travelTo(\Carbon\CarbonImmutable::parse('2022-02-17 08:00', 'Asia/Manila'));
        $this->configureAccountCredentials();
        Http::fake(['*get_pickup_time*' => Http::response([
            'ret_code' => 0, 'message' => 'success.',
            'data' => [[
                'date' => '2022-02-17', 'pickup_time' => 1645088400,
                'slots' => [
                    ['pickup_time_range_id' => 2, 'pickup_time_range' => '2:00pm-4:00pm'],
                    ['pickup_time_range_id' => 0, 'pickup_time_range' => 'Now'],
                ],
            ], [
                'date' => '2022-02-18', 'pickup_time' => 1645174800,
                'slots' => [
                    ['pickup_time_range_id' => 1, 'pickup_time_range' => '10:00am-12:00am'],
                    ['pickup_time_range_id' => 2, 'pickup_time_range' => '2:00pm-4:00pm'],
                ],
            ]],
        ])]);

        $slots = $this->courier()->getPickupSlots($this->makeOrder());

        $this->assertSame(['Thu, Feb 17 · Now', 'Thu, Feb 17 · 2:00pm-4:00pm', 'Fri, Feb 18 · 10:00am-12:00am', 'Fri, Feb 18 · 2:00pm-4:00pm'], array_column($slots, 'label'));
        $this->assertSame(['pickup_time' => 1645088400, 'pickup_time_range_id' => 0, 'pickup_time_range' => 'Now'], json_decode($slots[0]['value'], true));
        $this->assertSame(['pickup_time' => 1645174800, 'pickup_time_range_id' => 2, 'pickup_time_range' => '2:00pm-4:00pm'], json_decode($slots[3]['value'], true));
    }

    /** A day with no usable slots, or a day already over in Manila, is skipped, never a phantom option. */
    public function test_get_pickup_slots_skips_empty_and_past_days(): void
    {
        $this->travelTo(\Carbon\CarbonImmutable::parse('2022-02-18 08:00', 'Asia/Manila'));
        $this->configureAccountCredentials();
        Http::fake(['*get_pickup_time*' => Http::response([
            'ret_code' => 0, 'message' => 'success.',
            'data' => [
                ['date' => '2022-02-17', 'pickup_time' => 1645088400, 'slots' => [['pickup_time_range_id' => 0, 'pickup_time_range' => 'Now']]],
                ['date' => '2022-02-18', 'pickup_time' => 1645174800, 'slots' => []],
                [
                    'date' => '2022-02-19', 'pickup_time' => 1645261200,
                    'slots' => [['pickup_time_range_id' => 1, 'pickup_time_range' => '10:00am-12:00am']],
                ],
            ],
        ])]);

        $slots = $this->courier()->getPickupSlots($this->makeOrder());

        $this->assertCount(1, $slots);
        $this->assertSame(1645261200, json_decode($slots[0]['value'], true)['pickup_time']);
    }

    public function test_get_pickup_slots_is_empty_without_user_credentials(): void
    {
        Http::fake();
        $this->assertSame([], $this->courier()->getPickupSlots($this->makeOrder()));
        Http::assertNothingSent();
    }

    /** createShipment still sends the three pickup fields exactly as returned by a chosen date option (collapsed pickup_slot value flows through unchanged), switching collect_type to pickup. */
    public function test_create_shipment_sends_pickup_fields_from_a_chosen_date_option(): void
    {
        $this->travelTo(\Carbon\CarbonImmutable::parse('2022-02-17 08:00', 'Asia/Manila'));
        $this->configureAccountCredentials();

        Http::fake([
            '*batch_create_order*' => Http::response(['ret_code' => 0, 'message' => 'success.', 'data' => ['batch_no' => 207982800897461]]),
            '*get_order_create_result*' => Http::response($this->doneCreateResultResponse('SPEPH000000000002')),
        ]);

        $order = $this->makeOrder([], ['items' => [['name' => 'Guitar Strap', 'price' => 500, 'quantity' => 2]]]);

        // The exact JSON blob a chosen date option's `value` carries (see
        // getPickupSlots()) — createShipment() must decode and forward it
        // unchanged, same as before the date-collapse change.
        $pickupSlot = json_encode([
            'pickup_time'          => 1645088400,
            'pickup_time_range_id' => 0,
            'pickup_time_range'    => 'Now',
        ]);

        $this->courier()->createShipment($order, $this->opts([
            'service' => 'standard',
            'parcel'  => 'medium',
            'shipping_payment' => 'sender_pay',
            'pickup_slot' => $pickupSlot,
        ]));

        Http::assertSent(function ($request) {
            if (!str_contains($request->url(), 'batch_create_order')) {
                return false;
            }
            $o = $request->data()['orders'][0];

            return $o['fulfillment_info']['collect_type'] === 1
                && $o['fulfillment_info']['pickup_time'] === 1645088400
                && $o['fulfillment_info']['pickup_time_range_id'] === 0
                && $o['fulfillment_info']['pickup_time_range'] === 'Now';
        });
    }

    // ------------------------------------------------------------------
    // Option lists (getParcelOptions honors T1's spx_pouch_presets-or-default carry-forward).
    // ------------------------------------------------------------------

    public function test_get_parcel_options_falls_back_to_default_presets_plus_a_custom_sentinel(): void
    {
        $options = $this->courier()->getParcelOptions();

        $values = array_column($options, 'value');
        $this->assertSame(['small', 'medium', 'large', 'custom'], $values);
    }

    public function test_get_parcel_options_reads_the_configured_spx_pouch_presets_setting(): void
    {
        AccountSettings::for($this->account())->write('spx_pouch_presets', json_encode([
            ['label' => 'Tiny', 'length_cm' => 10, 'width_cm' => 10, 'height_cm' => 5, 'max_weight_kg' => 1],
        ]));

        $options = $this->courier()->getParcelOptions();
        $this->assertSame(['tiny', 'custom'], array_column($options, 'value'));
    }

    // ------------------------------------------------------------------
    // Task T3: printLabel() — 1.6 Get AWB V2, download+store, serve cached.
    // ------------------------------------------------------------------

    private function makeSpxOrder(string $trackingNumber, ?int $batchNo = 207982800897461): Shipment
    {
        return $this->makeShipment([], [
            'state' => 'booked',
            'tracking_number' => $trackingNumber,
            'meta' => $batchNo !== null ? ['batch_no' => $batchNo] : null,
        ]);
    }

    /**
     * Round 2 of the live-UAT gate finding requires the DOCUMENTED v2
     * endpoint to still short-circuit the whole flow when it settles on its
     * first attempt — no v1 fallback call at all. Fakes ONLY the v2 URL
     * (not v1) so a v1 call, if one wrongly happened, would hit an
     * unfaked/default response and this test's exact-count assertion would
     * catch it.
     */
    public function test_print_label_downloads_and_stores_the_awb_on_first_request(): void
    {
        $this->configureAccountCredentials();
        Storage::fake('local');
        $this->makeSpxOrder('SPEPH000000000001');

        Http::fake([
            '*/v2/order/batch_get_shipping_label*' => Http::response([
                'ret_code' => 0, 'message' => 'success.',
                'data' => [
                    'batch_no' => 207982800897461, 'task_status' => 4, 'description' => 'Done',
                    'awb_link' => 'https://test-stable.spx.co.th/downloads/resource/shipping_label/abc.pdf',
                    'orders' => [['order_id' => '1', 'tracking_no' => 'SPEPH000000000001']],
                ],
            ]),
            'https://test-stable.spx.co.th/downloads/resource/shipping_label/abc.pdf' => Http::response('%PDF-1.4 fake label bytes', 200),
        ]);

        $result = $this->courier()->printLabel('SPEPH000000000001', 'zebra');

        $this->assertTrue($result['success']);
        $this->assertSame('labels/spx/SPEPH000000000001.pdf', $result['path']);

        Storage::disk('local')->assertExists('labels/spx/SPEPH000000000001.pdf');
        $stored = Storage::disk('local')->get('labels/spx/SPEPH000000000001.pdf');
        $this->assertSame('%PDF-1.4 fake label bytes', $stored);
        // Live-UAT gate finding, round 3: only real PDF bytes ever get
        // stored — this is the magic-number check's happy path.
        $this->assertTrue(str_starts_with($stored, '%PDF'));

        // Exactly two calls: v2 (settled immediately, short-circuiting
        // BEFORE any v1 fallback call) + the PDF download.
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/v2/order/batch_get_shipping_label'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/v1/order/batch_get_shipping_label'));
    }

    public function test_print_label_refuses_an_awb_link_off_the_spx_allow_list(): void
    {
        $this->configureAccountCredentials();
        Storage::fake('local');
        $this->makeSpxOrder('SPEPH000000000009');

        Http::fake([
            '*/v2/order/batch_get_shipping_label*' => Http::response([
                'ret_code' => 0, 'message' => 'success.',
                'data' => ['batch_no' => 207982800897461, 'task_status' => 4, 'awb_link' => 'https://evil.example/abc.pdf'],
            ]),
        ]);

        $result = $this->courier()->printLabel('SPEPH000000000009', 'zebra');

        $this->assertFalse($result['success']);
        $this->assertTrue($result['unsafe']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'evil.example'));
        Storage::disk('local')->assertMissing('labels/spx/SPEPH000000000009.pdf');
    }

    public function test_print_label_treats_a_redirect_from_an_allowed_host_as_a_failure(): void
    {
        $this->configureAccountCredentials();
        Storage::fake('local');
        $this->makeSpxOrder('SPEPH000000000008');

        Http::fake([
            '*/v2/order/batch_get_shipping_label*' => Http::response([
                'ret_code' => 0, 'message' => 'success.',
                'data' => ['batch_no' => 207982800897461, 'task_status' => 4, 'awb_link' => 'https://test-stable.spx.co.th/r.pdf'],
            ]),
            'https://test-stable.spx.co.th/r.pdf' => Http::response('', 302, ['Location' => 'http://169.254.169.254/x']),
        ]);

        $result = $this->courier()->printLabel('SPEPH000000000008', 'zebra');

        $this->assertFalse($result['success']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '169.254'));
        Storage::disk('local')->assertMissing('labels/spx/SPEPH000000000008.pdf');
    }

    public function test_print_label_serves_the_cached_file_on_a_second_request_without_any_http_call(): void
    {
        $this->configureAccountCredentials();
        Storage::fake('local');
        $this->makeSpxOrder('SPEPH000000000005');

        Http::fake([
            '*/v2/order/batch_get_shipping_label*' => Http::response([
                'ret_code' => 0, 'message' => 'success.',
                'data' => ['batch_no' => 207982800897461, 'task_status' => 4, 'description' => 'Done', 'awb_link' => 'https://test-stable.spx.co.th/downloads/x.pdf'],
            ]),
            'https://test-stable.spx.co.th/downloads/x.pdf' => Http::response('%PDF fake bytes', 200),
        ]);

        $courier = $this->courier();
        $first = $courier->printLabel('SPEPH000000000005', 'zebra');
        $this->assertTrue($first['success']);
        Http::assertSentCount(2);

        // Second request: file already cached — zero additional HTTP calls,
        // same URL returned.
        $second = $courier->printLabel('SPEPH000000000005', 'zebra');
        $this->assertTrue($second['success']);
        $this->assertSame($first['path'], $second['path']);
        Http::assertSentCount(2); // unchanged
    }

    /**
     * Final-review fix (Finding 1): api.md §1.6 — a cancelled order's AWB
     * cannot be printed. The guard must fire server-side AND before the
     * cache check: a PDF cached while the booking was live must not keep
     * serving after the shipment is cancelled (that AWB is void).
     */
    public function test_print_label_refuses_a_cancelled_shipment_even_when_a_cached_pdf_exists(): void
    {
        $this->configureAccountCredentials();
        Storage::fake('local');

        $order = $this->makeSpxOrder('SPEPH000000000042');
        $order->update(['courier_status' => '7001']);

        // A label cached back when the booking was still live.
        Storage::disk('local')->put('labels/spx/SPEPH000000000042.pdf', '%PDF stale voided label');

        Http::fake();

        $result = $this->courier()->printLabel('SPEPH000000000042', 'zebra');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('cancelled', $result['error']);
        Http::assertNothingSent();
    }

    public function test_print_label_refuses_an_admin_cancelled_shipment_too(): void
    {
        $this->configureAccountCredentials();
        Storage::fake('local');

        // The literal status the admin Cancel action writes, before any
        // poll/webhook has overwritten it with SPX's own 7001.
        $order = $this->makeSpxOrder('SPEPH000000000043');
        $order->update(['courier_status' => 'canceled']);

        Http::fake();

        $result = $this->courier()->printLabel('SPEPH000000000043', 'zebra');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('cancelled', $result['error']);
        Http::assertNothingSent();
    }

    public function test_print_label_fails_gracefully_when_no_order_matches_the_tracking_number(): void
    {
        $this->configureAccountCredentials();
        Http::fake();

        $result = $this->courier()->printLabel('NO-SUCH-TRACKING', 'zebra');

        $this->assertFalse($result['success']);
        Http::assertNothingSent();
    }

    public function test_print_label_fails_gracefully_when_the_order_has_no_batch_no_on_file(): void
    {
        $this->configureAccountCredentials();
        $this->makeSpxOrder('SPEPH000000000006', batchNo: null);
        Http::fake();

        $result = $this->courier()->printLabel('SPEPH000000000006', 'zebra');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('batch number', $result['error']);
        Http::assertNothingSent();
    }

    /**
     * Round 2 of the live-UAT gate finding: v2 sits at task_status=3
     * ("Running") for BOTH short-window attempts, so the flow falls
     * through to the v1 fallback — which, in THIS test, is EXPLICITLY
     * faked to return a well-formed but unsettled array (`ret_code: 0`,
     * empty `data`: no `awb_link`/`fail_list`/`task_status`), so this
     * genuinely exercises the "v1 responded, but nothing in it settles"
     * branch of pollLabelResult()/labelResultSettled(), hermetically — see
     * this class's setUp() for why an unfaked URL is no longer an option
     * (a prior version of this test left v1 unmatched, which — without
     * Http::preventStrayRequests() — silently fell through to a REAL
     * outbound call to live SPX UAT on every run, per this task's own
     * final review). Neither phase ever settles -> printLabel must fail
     * cleanly rather than hang or crash.
     */
    public function test_print_label_fails_when_neither_v2_nor_the_v1_fallback_ever_settles(): void
    {
        $this->configureAccountCredentials();
        Storage::fake('local');
        $this->makeSpxOrder('SPEPH000000000007');

        Http::fake([
            '*/v2/order/batch_get_shipping_label*' => Http::response([
                'ret_code' => 0, 'message' => 'success.',
                'data' => ['batch_no' => 207982800897461, 'task_status' => 3, 'description' => 'Running', 'progress' => '0.00%'],
            ]),
            '*/v1/order/batch_get_shipping_label*' => Http::response([
                'ret_code' => 0, 'message' => 'success.',
                'data' => [], // well-formed, but no awb_link/fail_list/task_status — never settles.
            ]),
        ]);

        $result = $this->courier()->printLabel('SPEPH000000000007', 'zebra');

        $this->assertFalse($result['success']);
        Storage::disk('local')->assertMissing('labels/spx/SPEPH000000000007.pdf');
        // v2's SHORT window (2 attempts) + exactly ONE v1 fallback attempt —
        // never the original ~15s/6-attempt window.
        Http::assertSentCount(3);
    }

    /**
     * Round 2 of the live-UAT gate finding, THE core scenario: v2 (the
     * DOCUMENTED endpoint) reliably sits at `task_status: 3` ("Running",
     * `progress: "0.00%"`) indefinitely on real UAT — live-probed 8+
     * minutes with no change — so after its short window elapses without
     * settling, pollLabelResult() falls through to the UNDOCUMENTED v1
     * endpoint (absent from api.md; batch_no + tracking_no_list), which
     * live-probing found returns `{awb_link, fail_list}` SYNCHRONOUSLY.
     * That response is run through the exact same settled/parse logic as
     * v2's (round 1's shape-handling fix), so the label still downloads and
     * stores normally. See docs/spx/index.md's quirks list.
     */
    public function test_print_label_falls_back_to_v1_when_v2_stalls_at_running_and_stores_the_awb(): void
    {
        $this->configureAccountCredentials();
        Storage::fake('local');
        $this->makeSpxOrder('SPEPH000000000008');

        Http::fake([
            '*/v2/order/batch_get_shipping_label*' => Http::response([
                'ret_code' => 0, 'message' => 'success.',
                // v2 never settles — exactly the live-probed stall.
                'data' => ['batch_no' => 207982800897461, 'task_status' => 3, 'description' => 'Running', 'progress' => '0.00%'],
            ]),
            '*/v1/order/batch_get_shipping_label*' => Http::response([
                'ret_code' => 0, 'message' => 'success.',
                // No `task_status` key at all — exactly what the
                // undocumented v1 fallback returns on real UAT.
                'data' => [
                    'awb_link' => 'https://test-stable.spx.ph/downloads/resource/shipping_label/uat.pdf?access_token=abc',
                    'fail_list' => [],
                ],
            ]),
            'https://test-stable.spx.ph/downloads/resource/shipping_label/uat.pdf*' => Http::response('%PDF-1.4 uat label bytes', 200),
        ]);

        $result = $this->courier()->printLabel('SPEPH000000000008', 'zebra');

        $this->assertTrue($result['success']);
        Storage::disk('local')->assertExists('labels/spx/SPEPH000000000008.pdf');
        $this->assertSame('%PDF-1.4 uat label bytes', Storage::disk('local')->get('labels/spx/SPEPH000000000008.pdf'));

        // v2's short window (2 attempts) + the v1 fallback call + the PDF download.
        Http::assertSentCount(4);
        // The v1 fallback carries batch_no AND tracking_no_list (unlike v2,
        // which only ever sends batch_no) — proves the tracking number is
        // actually threaded through, not just the batch_no.
        Http::assertSent(function ($request) {
            if (!str_contains($request->url(), '/v1/order/batch_get_shipping_label')) {
                return false;
            }
            $body = $request->data();

            return $body['batch_no'] === 207982800897461
                && $body['tracking_no_list'] === ['SPEPH000000000008'];
        });
    }

    /**
     * Live-UAT gate finding: a per-tracking-number entry in `fail_list` is a
     * definitive failure to stop on immediately — reached here via the v1
     * fallback (the endpoint that actually returns fail_list on UAT),
     * after v2's short window stalls at Running exactly as in the success
     * case above.
     */
    public function test_print_label_surfaces_a_fail_list_entry_from_the_v1_fallback(): void
    {
        $this->configureAccountCredentials();
        Storage::fake('local');
        $this->makeSpxOrder('SPEPH000000000009');

        Http::fake([
            '*/v2/order/batch_get_shipping_label*' => Http::response([
                'ret_code' => 0, 'message' => 'success.',
                'data' => ['batch_no' => 207982800897461, 'task_status' => 3, 'description' => 'Running', 'progress' => '0.00%'],
            ]),
            '*/v1/order/batch_get_shipping_label*' => Http::response([
                'ret_code' => 0, 'message' => 'success.',
                'data' => [
                    'fail_list' => [
                        ['tracking_no' => 'SPEPH000000000009', 'message' => 'Order has been cancelled.'],
                    ],
                ],
            ]),
        ]);

        $result = $this->courier()->printLabel('SPEPH000000000009', 'zebra');

        $this->assertFalse($result['success']);
        $this->assertSame('Order has been cancelled.', $result['error']);
        Storage::disk('local')->assertMissing('labels/spx/SPEPH000000000009.pdf');
        // v2's short window (2 attempts) + exactly ONE v1 fallback attempt.
        Http::assertSentCount(3);
    }

    /**
     * T3 minor, ledgered and now fixed by the live-UAT gate finding: a
     * well-formed SpxApiException (SPX rejecting the request itself — bad
     * batch_no, auth, etc.) used to be swallowed by the poll's generic
     * `catch (\Throwable)`, producing the same misleading "still in
     * progress" message as a genuinely pending label. It must now surface
     * the actual SPX error instead. Round 2: v2 throws immediately (no
     * pointless 2nd v2 attempt against the same batch_no), the v1 fallback
     * is still given its own chance — and, faked identically here, throws
     * too, so ITS error (the more recent, more specific one) is what
     * surfaces.
     */
    public function test_print_label_surfaces_the_spx_api_exception_message_instead_of_still_in_progress(): void
    {
        $this->configureAccountCredentials();
        $this->makeSpxOrder('SPEPH000000000010');

        Http::fake([
            '*batch_get_shipping_label*' => Http::response([
                'ret_code' => 13001, 'message' => 'Order not found.',
            ]),
        ]);

        $result = $this->courier()->printLabel('SPEPH000000000010', 'zebra');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Order not found.', $result['error']);
        $this->assertStringNotContainsString('still in progress', $result['error']);
        // v2 throws on its FIRST attempt (no 2nd v2 attempt burned on a
        // well-formed API error) + one v1 fallback attempt (also throws,
        // same faked ret_code) — 2 total, never the old 1-attempt-only
        // count from round 1 (there was no fallback to try back then).
        Http::assertSentCount(2);
    }

    /**
     * Live-UAT gate finding, round 3 (final label finding): the v1
     * fallback's `awb_link` itself can respond HTTP 200 with a JSON error
     * body instead of a PDF — SPX's UAT label RENDERER failing
     * server-side, downstream of an otherwise valid `awb_link` (observed
     * live: `{"retcode":2501,"message":"invoke rpc failed",...}`, ~193
     * bytes, consistent across fresh links and retries). `$pdf->successful()`
     * doesn't catch this — HTTP-level the download genuinely succeeded, it's
     * just not a PDF. Bytes are checked for the `%PDF` magic number before
     * ever touching disk, so a corrupt "PDF" can never reach an admin's
     * download — the JSON body is decoded and its `retcode`/`message`
     * surfaced honestly instead.
     */
    public function test_print_label_surfaces_a_renderer_error_when_the_awb_link_returns_json_instead_of_a_pdf(): void
    {
        $this->configureAccountCredentials();
        Storage::fake('local');
        $this->makeSpxOrder('SPEPH000000000011');

        Http::fake([
            '*/v2/order/batch_get_shipping_label*' => Http::response([
                'ret_code' => 0, 'message' => 'success.',
                'data' => [
                    'awb_link' => 'https://test-stable.spx.ph/downloads/resource/shipping_label/broken.pdf?access_token=abc',
                    'fail_list' => [],
                ],
            ]),
            'https://test-stable.spx.ph/downloads/resource/shipping_label/broken.pdf*' => Http::response(
                json_encode(['retcode' => 2501, 'message' => 'invoke rpc failed']),
                200,
                ['Content-Type' => 'application/json']
            ),
        ]);

        $result = $this->courier()->printLabel('SPEPH000000000011', 'zebra');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('invoke rpc failed', $result['error']);
        $this->assertStringContainsString('2501', $result['error']);
        Storage::disk('local')->assertMissing('labels/spx/SPEPH000000000011.pdf');
    }

    // ------------------------------------------------------------------
    // Task T3: parseWebhook() — api.md §4.1 payload shapes, verbatim.
    // ------------------------------------------------------------------

    public function test_parse_webhook_normalizes_the_documented_pending_pickup_payload(): void
    {
        $events = $this->courier()->parseWebhook([
            'status' => 'Pending Pickup',
            'status_code' => '1001',
            'message' => 'xxx',
            'tracking_no' => 'SPXTH026817968592',
            'tracking_link' => 'https://test.spx.co.th/track?SPXTH026817968592',
            'order_id' => 'GW123456789',
            'order_id_link' => 'https://test.spx.co.th/track?GW123456789',
            'timestamp' => 1639385434,
            'id' => 'd4dcfea1-5286-4921-8fd3-7ded133f8579',
        ]);

        $this->assertCount(1, $events);
        $this->assertSame([
            'reference_id' => 'GW123456789',
            'tracking_number' => 'SPXTH026817968592',
            'status' => '1001',
            'occurred_at' => date(DATE_ATOM, 1639385434),
            'remarks' => 'xxx',
            'meta' => ['tracking_links' => ['delivery' => 'https://test.spx.co.th/track?SPXTH026817968592']],
        ], $events[0]);
    }

    public function test_parse_webhook_returns_no_events_for_a_payload_without_a_status_code(): void
    {
        $this->assertSame([], $this->courier()->parseWebhook(['foo' => 'bar']));
    }

    // ------------------------------------------------------------------
    // Task T3: verifyWebhookSignature() — same HMAC scheme as outbound
    // calls (see SpxClientTest for the low-level SpxClient::
    // verifyWebhookSignature() coverage); this proves SpxFulfillment wires
    // the RAW request bytes + all four headers through correctly.
    // ------------------------------------------------------------------

    public function test_verify_webhook_signature_accepts_a_correctly_signed_request(): void
    {
        AccountSettings::for($this->account())->write('spx_sandbox_app_id', '424242');
        AccountSettings::for($this->account())->writeSecret('spx_sandbox_secret_key', 'fake-app-secret-not-real');

        $payload = '{"status_code":"4001","tracking_no":"SPEPH1"}';
        $timestamp = 1700000000;
        $randomNum = 42;
        $sign = SpxClient::sign(424242, $timestamp, $randomNum, $payload, 'fake-app-secret-not-real');

        $request = Request::create('/webhooks/courier/spx', 'POST', content: $payload, server: [
            'HTTP_APP_ID' => '424242',
            'HTTP_CHECK_SIGN' => $sign,
            'HTTP_TIMESTAMP' => (string) $timestamp,
            'HTTP_RANDOM_NUM' => (string) $randomNum,
        ]);

        $this->assertTrue($this->courier()->verifyWebhookSignature($request));
    }

    public function test_verify_webhook_signature_rejects_a_request_with_no_signature_headers(): void
    {
        AccountSettings::for($this->account())->write('spx_sandbox_app_id', '424242');
        AccountSettings::for($this->account())->writeSecret('spx_sandbox_secret_key', 'fake-app-secret-not-real');

        $request = Request::create('/webhooks/courier/spx', 'POST', content: '{"status_code":"4001"}');

        $this->assertFalse($this->courier()->verifyWebhookSignature($request));
    }

    // ------------------------------------------------------------------
    // Owner task (2026-08-08, "SPX status from code to their actual
    // meaning"): the documented status vocabulary (docs/spx/guide.md,
    // "Order status code" table) exposed two ways — refreshStatusLabels()
    // (the existing "Refresh from SPX" button's contract) and statusLabels()
    // (available without that click). No Http::fake needed: neither method
    // makes an outbound call.
    // ------------------------------------------------------------------

    public function test_refresh_status_labels_returns_the_documented_vocabulary_without_any_http_call(): void
    {
        Http::fake();

        $result = $this->courier()->refreshStatusLabels();

        $this->assertTrue($result['ok']);
        $this->assertSame(SpxFulfillment::STATUS_LABELS, $result['labels']);
        $this->assertSame('Pending Pickup', $result['labels']['1001']);
        $this->assertSame('Cancelled', $result['labels']['7001']);
        Http::assertNothingSent();
    }

    public function test_status_labels_accessor_returns_the_same_documented_map(): void
    {
        $this->assertSame(SpxFulfillment::STATUS_LABELS, $this->courier()->statusLabels());
    }

    /** Every mappable status code must resolve to a real human label — no gaps in the table. */
    public function test_every_mappable_status_has_a_label(): void
    {
        $labels = $this->courier()->statusLabels();
        foreach ($this->courier()->mappableStatuses() as $code) {
            $this->assertArrayHasKey($code, $labels, "Missing label for status code {$code}");
        }
    }

    public function test_create_shipment_sends_the_courier_message_as_deliver_instruction(): void
    {
        $this->configureAccountCredentials();

        Http::fake([
            '*batch_create_order*' => Http::response(['ret_code' => 0, 'message' => 'success.', 'data' => ['batch_no' => 207982800897461]]),
            '*get_order_create_result*' => Http::response($this->doneCreateResultResponse('SPEPH000000000002')),
        ]);

        // Only the shipment's courier note rides deliver_instruction.
        $order = $this->makeOrder([], [
            'courier_note' => 'Deliver between Aug 20 and Aug 22',
            'items' => [['name' => 'Guitar Strap', 'price' => 500, 'quantity' => 1]],
        ]);

        $result = $this->courier()->createShipment($order, $this->opts([
            'service' => 'standard',
            'parcel' => 'medium',
            'shipping_payment' => 'sender_pay',
        ]));
        $this->assertTrue($result['success']);

        Http::assertSent(function ($request) {
            if (!str_contains($request->url(), 'batch_create_order')) {
                return false;
            }
            $o = $request->data()['orders'][0];

            return $o['deliver_info']['deliver_instruction'] === 'Deliver between Aug 20 and Aug 22';
        });
    }

    // ------------------------------------------------------------------
    // Ventaship: per-account settings, the shipper account and the tracking lookup.
    // ------------------------------------------------------------------

    public function test_enabled_follows_the_account_status_and_the_app_pair(): void
    {
        $this->assertFalse($this->courier()->isEnabled());

        $this->configureAccountCredentials();
        $this->assertTrue($this->courier()->isEnabled());

        $this->account()->update(['status' => 'suspended']);
        $this->assertFalse($this->courier()->isEnabled());
    }

    public function test_the_schema_has_no_enabled_toggle_or_mode_and_marks_both_secrets(): void
    {
        $schema = collect($this->courier()->settingsSchema());

        $this->assertNull($schema->firstWhere('key', 'spx_enabled'));
        $this->assertNull($schema->firstWhere('key', 'spx_mode'));
        $this->assertTrue($schema->firstWhere('key', 'spx_sandbox_secret_key')['encrypted']);
        $this->assertTrue($schema->firstWhere('key', 'spx_sandbox_user_secret')['encrypted']);
    }

    public function test_create_shipper_account_sends_the_given_phone_and_email_and_stores_the_pair(): void
    {
        config(['mail.from.address' => 'never-sent@example.com']);
        AccountSettings::for($this->account())->write('spx_sandbox_app_id', '424242');
        AccountSettings::for($this->account())->writeSecret('spx_sandbox_secret_key', 'fake-app-secret-not-real');

        Http::fake(['*account/create*' => Http::response([
            'ret_code' => 0, 'message' => 'success.', 'data' => ['user_id' => 555001, 'user_secret' => 'issued-user-secret'],
        ])]);

        $result = $this->courier()->createShipperAccount('+63 917 123 4567', 'shop@example.com');

        $this->assertTrue($result['ok']);
        Http::assertSent(fn ($request) => $request->data() === ['phone' => '639171234567', 'email' => 'shop@example.com']);

        $settings = AccountSettings::for($this->account());
        $this->assertSame('555001', $settings->read('spx_sandbox_user_id'));
        $this->assertSame('issued-user-secret', $settings->secret('spx_sandbox_user_secret'));
        $this->assertNotSame('issued-user-secret', $settings->read('spx_sandbox_user_secret'), 'The user secret is stored encrypted.');
    }

    public function test_create_shipper_account_refuses_a_bad_phone_or_email_and_an_existing_pair(): void
    {
        $this->configureAccountCredentials();
        Http::fake();

        $this->assertStringContainsString('already on file', $this->courier()->createShipperAccount('09171234567', 'shop@example.com')['error']);

        AccountSettings::for($this->account())->write('spx_sandbox_user_id', null);
        $this->assertStringContainsString('PH mobile', $this->courier()->createShipperAccount('12', 'shop@example.com')['error']);
        $this->assertStringContainsString('email', $this->courier()->createShipperAccount('09171234567', 'not-an-email')['error']);

        Http::assertNothingSent();
    }

    public function test_a_label_is_only_found_on_the_same_account(): void
    {
        $this->configureAccountCredentials();
        $this->makeSpxOrder('SPEPH000000000077');
        Http::fake();

        $other = $this->spxAccount();
        $result = (new SpxFulfillment($other, AccountSettings::for($other), sleeper: $this->noSleep()))->printLabel('SPEPH000000000077', 'pdf');

        $this->assertFalse($result['success']);
        Http::assertNothingSent();
    }

    public function test_a_tracking_number_that_is_not_a_plain_token_is_refused_before_any_lookup(): void
    {
        $this->configureAccountCredentials();
        Storage::fake('local');
        Http::fake();

        $result = $this->courier()->printLabel('../../etc/passwd', 'pdf');

        $this->assertFalse($result['success']);
        Http::assertNothingSent();
    }

    public function test_cod_collects_the_cod_amount_not_the_total(): void
    {
        $this->configureAccountCredentials();
        Http::fake([
            '*batch_create_order*' => Http::response(['ret_code' => 0, 'message' => 'success.', 'data' => ['batch_no' => 1]]),
            '*get_order_create_result*' => Http::response($this->doneCreateResultResponse('SPEPH000000000088', 1)),
        ]);

        $order = $this->makeOrder([], ['payment_method' => 'cod', 'total' => 1500.00, 'cod_amount' => 1290.00]);
        $this->assertTrue($this->courier()->createShipment($order, $this->opts(['parcel' => 'small']))['success']);

        Http::assertSent(function ($request) {
            if (!str_contains($request->url(), 'batch_create_order')) {
                return false;
            }
            $info = $request->data()['orders'][0]['fulfillment_info'];

            return $info['cod_collection'] === 1 && $info['cod_amount'] === 1290;
        });
    }

    public function test_cod_with_centavos_no_amount_or_a_contradicting_buyer_payment_is_refused(): void
    {
        $this->configureAccountCredentials();
        Http::fake();

        $centavos = $this->makeOrder([], ['payment_method' => 'cod', 'total' => 1500, 'cod_amount' => 1290.50]);
        $this->assertStringContainsString('whole pesos', $this->courier()->createShipment($centavos, $this->opts(['parcel' => 'small']))['error']);

        $noAmount = $this->makeOrder([], ['payment_method' => 'cod', 'cod_amount' => null]);
        $this->assertStringContainsString('needs a cod_amount', $this->courier()->createShipment($noAmount, $this->opts(['parcel' => 'small']))['error']);

        $prepaid = $this->makeOrder();
        $this->assertStringContainsString('the shipment is prepaid', $this->courier()->createShipment($prepaid, $this->opts(['parcel' => 'small', 'buyer_payment' => 'cod']))['error']);

        Http::assertNothingSent();
    }

    public function test_the_insured_value_is_the_declared_value_not_the_total(): void
    {
        $this->configureAccountCredentials();
        Http::fake([
            '*batch_create_order*' => Http::response(['ret_code' => 0, 'message' => 'success.', 'data' => ['batch_no' => 1]]),
            '*get_order_create_result*' => Http::response($this->doneCreateResultResponse('SPEPH000000000089', 1)),
        ]);

        $order = $this->makeOrder([], ['total' => 1500.00, 'declared_value' => 800.00]);
        $this->assertTrue($this->courier()->createShipment($order, $this->opts(['parcel' => 'small', 'insure' => true]))['success']);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'batch_create_order')
            && (float) $request->data()['orders'][0]['parcel_info']['express_insured_value'] === 800.0);
    }

    public function test_booking_tracking_link_from_spx_create_result_is_the_shipment_link(): void
    {
        // Shape of a real UAT booking's stored response (1.5 Get Order Create Result): SPX names it tracking_link.
        $order = \App\Couriers\CourierOrder::make(['courier_meta' => ['booked_response' => [
            'order_id' => 'VS000002',
            'tracking_no' => 'SPEPH06048584367B',
            'tracking_link' => 'https://uat.spx.ph/track?SPEPH06048584367B',
            'order_id_link' => 'https://uat.spx.ph/track?VS000002',
        ]]]);

        $this->assertSame([['label' => 'Track', 'url' => 'https://uat.spx.ph/track?SPEPH06048584367B']], $this->courier()->trackingLinks($order));
    }

    // ------------------------------------------------------------------
    // Batch B (money and status): fees, failure reasons, webhook facts, ODZ.
    // ------------------------------------------------------------------

    public function test_create_shipment_reports_the_estimated_fee_and_the_booked_data(): void
    {
        $this->configureAccountCredentials();
        Http::fake([
            '*batch_create_order*' => Http::response(['ret_code' => 0, 'message' => 'success.', 'data' => ['batch_no' => 77]]),
            '*get_order_create_result*' => Http::response($this->doneCreateResultResponse('SPEPH000000000001', 77, [
                'orders' => [[
                    'order_id' => 'VS000042', 'tracking_no' => 'SPEPH000000000001',
                    'tracking_link' => 'https://uat.spx.ph/track?SPEPH000000000001',
                    'estimated_shipping_fee' => 55, 'basic_shipping_fee' => 195, 'voucher_shipping_fee' => 140,
                    'remote_area_fee' => 0, 'r_first_sort_code' => 'S1',
                ]],
            ])),
        ]);

        $result = $this->courier()->createShipment($this->makeOrder(), $this->opts(['parcel' => 'small']));

        $this->assertTrue($result['success']);
        $this->assertSame('55.00', $result['fee']);
        $this->assertSame('55.00', $result['meta']['estimated_shipping_fee']);
        $this->assertSame('0.00', $result['meta']['remote_area_fee']);
        $this->assertSame('S1', $result['meta']['booked_response']['r_first_sort_code']);
        $this->assertSame(['delivery' => 'https://uat.spx.ph/track?SPEPH000000000001'], $result['meta']['tracking_links']);
        $this->assertArrayNotHasKey('edd', $result['meta'], 'the real 1.5 answer carries no EDD');
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function createFailureProvider(): array
    {
        return [
            'known code with debug_msg' => [
                ['order_id' => 'VS000042', 'message' => 'Create Order Failed.', 'debug_msg' => 'now_time over cutoff_time', 'ret_code' => 10080],
                "The pickup slot is past SPX's cutoff. Choose another pickup date. (SPX 10080: now_time over cutoff_time)",
            ],
            'known code without debug_msg keeps SPX message' => [
                ['message' => 'Create Order Failed.', 'ret_code' => 10019],
                "SPX doesn't serve the delivery address. (SPX 10019: Create Order Failed.)",
            ],
            'unknown code in SPX words' => [
                ['message' => 'Receiver name too long.', 'debug_msg' => "deliver_name\nlength 80 > 64", 'ret_code' => 10099],
                'Receiver name too long. (SPX 10099: deliver_name length 80 > 64)',
            ],
            'unknown code, no debug_msg' => [
                ['message' => 'Create Order Failed.', 'ret_code' => 10099],
                'Create Order Failed. (SPX 10099)',
            ],
            'no code no message' => [[], 'SPX could not create this shipment.'],
        ];
    }

    #[DataProvider('createFailureProvider')]
    public function test_a_create_failure_names_the_reason_then_spx_code_and_debug_msg(array $fail, string $expected): void
    {
        $this->configureAccountCredentials();
        Http::fake([
            '*batch_create_order*' => Http::response(['ret_code' => 0, 'message' => 'success.', 'data' => ['batch_no' => 5]]),
            '*get_order_create_result*' => Http::response(['ret_code' => 0, 'message' => 'success.', 'data' => [
                'batch_no' => 5, 'task_status' => 4, 'description' => 'Done', 'total_count' => 1, 'success_count' => 0,
                'fail_count' => 1, 'progress' => '100.00%', 'orders' => [], 'fail_list' => [$fail],
            ]]),
        ]);

        $result = $this->courier()->createShipment($this->makeOrder(), $this->opts(['parcel' => 'small']));

        $this->assertFalse($result['success']);
        $this->assertSame($expected, $result['error']);
    }

    public function test_parse_webhook_carries_the_actual_fee_edd_driver_and_proofs(): void
    {
        [$event] = $this->courier()->parseWebhook([
            'status' => 'Delivered', 'status_code' => '4001', 'message' => 'Delivered',
            'tracking_no' => 'SPEPH1', 'order_id' => 'VS000042', 'timestamp' => 1762570000, 'id' => 'x',
            'tracking_link' => 'https://uat.spx.ph/track?SPEPH1',
            'actual_shipping_fee' => 57.5,
            'edd_min' => 1762570000, 'edd_max' => 1762742800,
            'driver_name' => '  Juan  ', 'driver_phone_number' => '09170000000',
            'proof_of_delivery_list' => ['https://cdn.spx.ph/pod/1.jpg', 'javascript:alert(1)', 'https://cdn.spx.ph/pod/1.jpg', 'ftp://x/y'],
        ]);

        $this->assertSame('57.50', $event['actual_fee']);
        $this->assertSame([
            'tracking_links' => ['delivery' => 'https://uat.spx.ph/track?SPEPH1'],
            'edd' => ['min' => 1762570000, 'max' => 1762742800, 'source' => 'webhook'],
            'driver' => ['name' => 'Juan', 'phone' => '09170000000'],
            'proofs' => ['epod' => ['https://cdn.spx.ph/pod/1.jpg']],
        ], $event['meta']);
    }

    public function test_parse_webhook_reads_the_return_fee_on_6003_and_no_actual_fee_when_absent(): void
    {
        [$event] = $this->courier()->parseWebhook([
            'status_code' => '6003', 'tracking_no' => 'SPEPH1', 'timestamp' => 1762570000, 'actual_return_shipping_fee' => 35,
        ]);

        $this->assertArrayNotHasKey('actual_fee', $event);
        $this->assertSame(['actual_return_fee' => '35.00'], $event['meta']);
    }

    public function test_parse_webhook_reads_a_4_2_evidence_push_as_a_statusless_event(): void
    {
        $events = $this->courier()->parseWebhook([
            'tracking_no' => 'SPEPH1', 'customer_order_id' => 'VS000042', 'id' => 'ep-1',
            'epod_list' => ['https://cdn.spx.ph/epod/a.jpg'], 'epop_list' => ['https://cdn.spx.ph/epop/b.jpg'],
            'epor_list' => [], 'epooh_list' => null, 'status' => 'Delivered',
        ]);

        $this->assertSame([[
            'reference_id' => 'VS000042', 'tracking_number' => 'SPEPH1', 'status' => null,
            'occurred_at' => null, 'remarks' => null,
            'meta' => ['proofs' => ['epod' => ['https://cdn.spx.ph/epod/a.jpg'], 'epop' => ['https://cdn.spx.ph/epop/b.jpg']]],
        ]], $events);
        $this->assertSame([], $this->courier()->parseWebhook(['tracking_no' => 'SPEPH1', 'epod_list' => []]), 'no proofs, no event');
    }

    public function test_quote_and_coverage_carry_odz_from_remote_area_fee_when_spx_sends_it(): void
    {
        $this->configureAccountCredentials();
        Http::fakeSequence('*batch_check_order*')
            ->push(['ret_code' => 0, 'message' => 'success.', 'data' => ['orders' => [['estimated_shipping_fee' => 80, 'remote_area_fee' => 25]], 'fail_list' => []]])
            ->push(['ret_code' => 0, 'message' => 'success.', 'data' => ['orders' => [['estimated_shipping_fee' => 55, 'remote_area_fee' => 0]], 'fail_list' => []]])
            ->push(['ret_code' => 0, 'message' => 'success.', 'data' => ['orders' => [['estimated_shipping_fee' => 55]], 'fail_list' => []]])
            ->push(['ret_code' => 0, 'message' => 'success.', 'data' => ['orders' => [['estimated_shipping_fee' => 80, 'remote_area_fee' => 25]], 'fail_list' => []]])
            ->push(['ret_code' => 0, 'message' => 'success.', 'data' => ['orders' => [['estimated_shipping_fee' => 55]], 'fail_list' => []]]);

        $order = $this->makeOrder();
        $opts = $this->opts(['parcel' => 'small']);
        $remote = $this->courier()->estimateFee($order, $opts);
        $plain = $this->courier()->estimateFee($order, $opts);
        $silent = $this->courier()->estimateFee($order, $opts);

        $this->assertSame([true, '25.00'], [$remote->odz, $remote->remote_area_fee]);
        $this->assertSame([false, '0.00'], [$plain->odz, $plain->remote_area_fee]);
        $this->assertSame([null, null], [$silent->odz, $silent->remote_area_fee]);

        $this->assertTrue($this->courier()->checkServiceability($order, $this->pickup())->odz);
        $verdict = $this->courier()->checkServiceability($order, $this->pickup());
        $this->assertTrue($verdict->isServiceable());
        $this->assertNull($verdict->odz);
    }
}
