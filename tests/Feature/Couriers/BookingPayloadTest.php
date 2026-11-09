<?php

namespace Tests\Feature\Couriers;

use App\Couriers\AccountSettings;
use App\Couriers\CourierOrder;
use App\Couriers\CourierRegistry;
use App\Couriers\PickupPoint;
use App\Couriers\Spx\SpxFulfillment;
use App\Couriers\Spx\SpxLocations;
use App\Couriers\Support\ServiceabilityVerdict;
use App\Models\CourierAccount;
use App\Models\Shipment;
use App\Shipments\CourierOptions;
use App\Shipments\Jobs\BookShipment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsCourierAccounts;
use Tests\Concerns\LbcFixtures;
use Tests\TestCase;

/**
 * What each real courier adapter sends for a stored shipment, read off the faked HTTP request: QuadX's items and
 * total, SPX's weight, limits and item type, LBC's COD declared value and pickup date.
 */
class BookingPayloadTest extends TestCase
{
    use BuildsCourierAccounts, LbcFixtures, RefreshDatabase;

    private const QUADX_CREATE = 'https://api.staging.quadx.xyz/v2/orders';

    // --- QuadX -----------------------------------------------------------------

    public function test_quadx_consolidates_unpriced_items_and_total_equals_value(): void
    {
        Http::fake(['api.staging.quadx.xyz/*' => Http::response($this->quadxFixture('order-created'))]);
        $account = $this->quadxAccount();

        // A UI shipment: items without prices, total = Value.
        $this->bookQuadx($this->quadxShipment($account, [
            'items' => [['name' => 'Shirt', 'quantity' => 2, 'price' => null], ['name' => 'Cap', 'quantity' => 1, 'price' => null]],
            'total' => '750.00',
        ]));
        // A COD UI shipment: cod_amount = total = Value.
        $this->bookQuadx($this->quadxShipment($account, [
            'courier_ref' => 'VS000002',
            'payment_method' => 'cod',
            'items' => [['name' => 'Shirt', 'quantity' => 2, 'price' => null]],
            'total' => '1290.00',
            'cod_amount' => '1290.00',
        ]));
        // API items whose prices don't add up to the total.
        $this->bookQuadx($this->quadxShipment($account, [
            'courier_ref' => 'VS000003',
            'items' => [['name' => 'Blue Tee', 'quantity' => 2, 'price' => 250]],
            'total' => '600.00',
        ]));
        // A very long list of names is cut to QuadX's 500 characters.
        $this->bookQuadx($this->quadxShipment($account, [
            'courier_ref' => 'VS000004',
            'items' => array_fill(0, 60, ['name' => 'Long product name here', 'quantity' => 1]),
            'total' => '100.00',
        ]));

        $sent = $this->quadxBodies();
        $this->assertCount(4, $sent);

        $this->assertSame([['type' => 'product', 'description' => '2 × Shirt, 1 × Cap', 'amount' => 750.0, 'quantity' => 1]], $sent[0]['items']);
        $this->assertEquals(750, $sent[0]['total']);

        $this->assertSame('cod', $sent[1]['payment_method']);
        $this->assertSame([['type' => 'product', 'description' => '2 × Shirt', 'amount' => 1290.0, 'quantity' => 1]], $sent[1]['items']);
        $this->assertEquals(1290, $sent[1]['total']);

        $this->assertSame([['type' => 'product', 'description' => '2 × Blue Tee', 'amount' => 600.0, 'quantity' => 1]], $sent[2]['items']);
        $this->assertEquals(600, $sent[2]['total']);

        $this->assertCount(1, $sent[3]['items']);
        $this->assertSame(500, mb_strlen($sent[3]['items'][0]['description']));
        $this->assertEquals(100, $sent[3]['items'][0]['amount']);

        foreach ($sent as $body) {
            $sum = array_sum(array_map(fn ($i) => $i['amount'] * $i['quantity'], $body['items']));
            $this->assertEquals($body['total'], $sum, 'QuadX total matches the sum of its items.');
        }
    }

    public function test_quadx_keeps_itemised_prices_when_they_sum(): void
    {
        Http::fake(['api.staging.quadx.xyz/*' => Http::response($this->quadxFixture('order-created'))]);
        $account = $this->quadxAccount();

        $this->bookQuadx($this->quadxShipment($account, [
            'items' => [['name' => 'Blue Tee', 'quantity' => 2, 'price' => '500.00'], ['name' => 'Cap', 'quantity' => 1, 'price' => '200.10']],
            'total' => '1200.10',
        ]));

        $body = $this->quadxBodies()[0];
        $this->assertSame([
            ['type' => 'product', 'description' => 'Blue Tee', 'amount' => 500.0, 'quantity' => 2],
            ['type' => 'product', 'description' => 'Cap', 'amount' => 200.1, 'quantity' => 1],
        ], $body['items']);
        $this->assertEquals(1200.10, $body['total']);
    }

    public function test_quadx_refuses_zero_total(): void
    {
        Http::fake();
        $account = $this->quadxAccount();

        $shipment = $this->quadxShipment($account, [
            'items' => [['name' => 'Shirt', 'quantity' => 1, 'price' => null]],
            'total' => '0.00',
        ]);
        $result = $this->bookQuadx($shipment);
        $this->assertFalse($result['success']);
        $this->assertSame('Parcel value is required.', $result['error']);

        $under = $this->quadxShipment($account, ['courier_ref' => 'VS000002', 'total' => '0.50']);
        $this->assertSame('Parcel value is required.', $this->bookQuadx($under)['error']);

        // Through the booking job: recorded as failed, nothing sent.
        $queued = $this->quadxShipment($account, ['courier_ref' => 'VS000003', 'total' => null, 'state' => 'queued']);
        Bus::dispatchSync(new BookShipment($queued->id));
        $queued->refresh();
        $this->assertSame('failed', $queued->state);
        $this->assertSame('Parcel value is required.', $queued->error_message);

        Http::assertNothingSent();
    }

    // --- SPX -------------------------------------------------------------------

    public function test_spx_sends_typed_weight_with_preset_dimensions(): void
    {
        $this->fakeSpx();
        $account = $this->spxAccount();

        $this->bookSpx($account, $this->spxShipment($account, ['parcel' => ['type' => 'medium', 'weight_kg' => 1.2]]));
        $this->bookSpx($account, $this->spxShipment($account, [
            'parcel' => ['type' => 'custom', 'weight_kg' => 7.5, 'length_cm' => 60, 'width_cm' => 40, 'height_cm' => 30],
        ], ['courier_ref' => 'VS000002']));

        [$preset, $custom] = $this->spxParcels();

        $this->assertEquals(1.2, $preset['parcel_weight']);
        $this->assertEquals([35, 25, 15], [$preset['parcel_length'], $preset['parcel_width'], $preset['parcel_height']]);

        $this->assertEquals(7.5, $custom['parcel_weight']);
        $this->assertEquals([60, 40, 30], [$custom['parcel_length'], $custom['parcel_width'], $custom['parcel_height']]);
    }

    public function test_spx_limits_refused(): void
    {
        $this->fakeSpx();
        $account = $this->spxAccount();

        $cases = [
            'Enter the parcel weight.' => [['type' => 'small'], []],
            'SPX weight must be under 50 kg.' => [['type' => 'large', 'weight_kg' => 50], []],
            'SPX sides can\'t exceed 150 cm.' => [['type' => 'custom', 'weight_kg' => 5, 'length_cm' => 151, 'width_cm' => 40, 'height_cm' => 30], []],
            'SPX COD must be whole pesos up to ₱50,000.' => [['type' => 'small', 'weight_kg' => 1], ['payment_method' => 'cod', 'total' => '50001.00', 'cod_amount' => '50001.00']],
        ];

        $n = 0;
        foreach ($cases as $message => [$parcel, $attributes]) {
            $n++;
            $shipment = $this->spxShipment($account, ['parcel' => $parcel], $attributes + ['courier_ref' => 'VS00010' . $n]);
            $result = $this->bookSpx($account, $shipment);
            $this->assertFalse($result['success'], $message);
            $this->assertSame($message, $result['error']);
        }

        // Centavos keep their existing refusal.
        $centavos = $this->spxShipment($account, ['parcel' => ['type' => 'small', 'weight_kg' => 1]], [
            'courier_ref' => 'VS000200', 'payment_method' => 'cod', 'total' => '1290.50', 'cod_amount' => '1290.50',
        ]);
        $this->assertStringContainsString('whole pesos', $this->bookSpx($account, $centavos)['error']);

        Http::assertNothingSent();

        // The limits themselves pass.
        $edge = $this->spxShipment($account, [
            'parcel' => ['type' => 'custom', 'weight_kg' => 49.99, 'length_cm' => 150, 'width_cm' => 150, 'height_cm' => 150],
        ], ['courier_ref' => 'VS000300', 'payment_method' => 'cod', 'total' => '50000.00', 'cod_amount' => '50000.00']);
        $this->assertTrue($this->bookSpx($account, $edge)['success']);
        $sent = Http::recorded(fn (Request $r) => str_contains($r->url(), 'batch_create_order'))->values();
        $this->assertCount(1, $sent);
        $this->assertSame(50000, $sent[0][0]->data()['orders'][0]['fulfillment_info']['cod_amount']);
    }

    public function test_spx_cod_over_limit_with_centavos_refused(): void
    {
        $this->fakeSpx();
        $account = $this->spxAccount();

        $shipment = $this->spxShipment($account, ['parcel' => ['type' => 'small', 'weight_kg' => 1]], [
            'courier_ref' => 'VS000400', 'payment_method' => 'cod', 'total' => '50000.50', 'cod_amount' => '50000.50',
        ]);
        $result = $this->bookSpx($account, $shipment);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('SPX', $result['error']);
        Http::assertNothingSent();
    }

    public function test_spx_category_passthrough(): void
    {
        $this->fakeSpx();
        $account = $this->spxAccount();

        $this->bookSpx($account, $this->spxShipment($account, ['parcel' => ['type' => 'small', 'weight_kg' => 1, 'category' => 'Electronics']]));
        $this->bookSpx($account, $this->spxShipment($account, ['parcel' => ['type' => 'small', 'weight_kg' => 1, 'category' => 'electronics']], ['courier_ref' => 'VS000002']));
        $this->bookSpx($account, $this->spxShipment($account, ['parcel' => ['type' => 'small', 'weight_kg' => 1]], ['courier_ref' => 'VS000003']));
        $this->bookSpx($account, $this->spxShipment($account, ['parcel' => ['type' => 'small', 'weight_kg' => 1, 'category' => 'Food and beverage']], ['courier_ref' => 'VS000004']));

        $this->assertSame(
            ['Electronics', 'General merchandise', 'General merchandise', 'Food and beverage'],
            array_column($this->spxParcels(), 'parcel_item_type'),
        );
    }

    public function test_spx_sends_addresses_in_spx_location_hierarchy(): void
    {
        $this->fakeSpx();
        $account = $this->spxAccount();

        $shipment = $this->spxShipment($account, ['parcel' => ['type' => 'small', 'weight_kg' => 1]], [
            'recipient' => ['district' => 'Ermita', 'barangay' => 'Barangay 659', 'city' => 'Manila', 'state' => 'Metro Manila', 'postcode' => '1000'] + $this->recipient(),
            'pickup' => ['barangay' => 'Pinagbuhatan', 'city' => 'Pasig', 'postcode' => '1602'] + $this->pickupArray(),
        ]);
        $this->assertTrue($this->bookSpx($account, $shipment)['success']);

        $order = Http::recorded(fn (Request $r) => str_contains($r->url(), 'batch_create_order'))->first()[0]->data()['orders'][0];
        $this->assertSame(
            ['Metro Manila', 'Metro Manila', 'Ermita', 'Barangay 659', '12 Mabini St'],
            array_values(array_intersect_key($order['deliver_info'], array_flip(['deliver_state', 'deliver_city', 'deliver_district', 'deliver_street', 'deliver_detail_address']))),
        );
        $this->assertSame(
            ['Metro Manila', 'Metro Manila', 'Pasig City', 'Pinagbuhatan'],
            array_values(array_intersect_key($order['sender_info'], array_flip(['sender_state', 'sender_city', 'sender_district', 'sender_street']))),
        );
    }

    public function test_spx_refuses_an_address_it_does_not_list_before_sending(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $account = $this->spxAccount();
        $parcel = ['parcel' => ['type' => 'small', 'weight_kg' => 1]];

        $cases = [
            "SPX doesn't list the customer's barangay." => [['barangay' => 'Barangay 99999'], []],
            "SPX doesn't list the customer's city." => [['city' => "T'Boli", 'state' => 'South Cotabato', 'barangay' => 'Aflek'], []],
            "SPX doesn't list the customer's province." => [['state' => 'Atlantis'], []],
            "SPX doesn't list the sender's barangay." => [[], ['barangay' => 'Nowhere']],
            "SPX doesn't deliver to this barangay." => [['state' => 'Antique', 'city' => 'Pandan', 'barangay' => 'Aracay'], []],
        ];
        $n = 0;
        foreach ($cases as $message => [$to, $from]) {
            $shipment = $this->spxShipment($account, $parcel, [
                'courier_ref' => 'VS00050'.++$n,
                'recipient' => $to + $this->recipient(),
                'pickup' => $from + $this->pickupArray(),
            ]);
            $result = $this->bookSpx($account, $shipment);
            $this->assertFalse($result['success'], $message);
            $this->assertSame($message, $result['error']);
        }
        Http::assertNothingSent();
    }

    public function test_spx_refuses_cod_and_pickup_where_spx_does_not_offer_them(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $dir = sys_get_temp_dir().'/spx-flags-'.bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents("$dir/l.json", json_encode([
            ['Visayas', 'Cebu', 'Cebu City', 'Lahug (Pob.)', '111000'],       // delivers, no COD delivery
            ['Metro Manila', 'Metro Manila', 'Mandaluyong City', 'Wack-wack Greenhills', '011100'], // no pickup
        ]));
        $this->app->instance(SpxLocations::class, new SpxLocations("$dir/l.json", "$dir/c"));
        $account = $this->spxAccount();
        $slot = json_encode(['pickup_time' => 1767225600, 'pickup_time_range_id' => 1, 'pickup_time_range' => '09:00-12:00']);

        $cod = $this->spxShipment($account, ['parcel' => ['type' => 'small', 'weight_kg' => 1]], [
            'payment_method' => 'cod', 'total' => '500.00', 'cod_amount' => '500.00',
        ]);
        $this->assertSame("SPX doesn't collect COD in this barangay.", $this->bookSpx($account, $cod)['error']);

        $pickup = $this->spxShipment($account, ['parcel' => ['type' => 'small', 'weight_kg' => 1], 'pickup_slot' => $slot], ['courier_ref' => 'VS000601']);
        $this->assertSame("SPX doesn't pick up from the sender's barangay.", $this->bookSpx($account, $pickup)['error']);

        // The same sender is fine for a drop-off.
        $courier = new SpxFulfillment($account->fresh(), AccountSettings::for($account));
        $verdict = $courier->checkServiceability(CourierOrder::fromApi($cod), PickupPoint::fromArray($this->pickupArray()));
        $this->assertSame(ServiceabilityVerdict::KIND_COD, $verdict->toArray()['kind']);
        Http::assertNothingSent();

        array_map('unlink', [...glob("$dir/c/*"), "$dir/l.json"]);
        rmdir("$dir/c");
        rmdir($dir);
    }

    public function test_spx_serviceability_refuses_an_unlisted_destination_without_asking(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $account = $this->spxAccount();
        $shipment = $this->spxShipment($account, [], ['recipient' => ['barangay' => 'Barangay 99999'] + $this->recipient()]);

        $courier = new SpxFulfillment($account->fresh(), AccountSettings::for($account));
        $verdict = $courier->checkServiceability(CourierOrder::fromApi($shipment), PickupPoint::fromArray($this->pickupArray()));

        $this->assertSame(ServiceabilityVerdict::KIND_DESTINATION, $verdict->toArray()['kind']);
        $this->assertTrue($verdict->isRefusal());
        Http::assertNothingSent();
    }

    // --- LBC -------------------------------------------------------------------

    public function test_lbc_cod_declared_at_least_cod_amount(): void
    {
        $cases = [
            // [cod_amount, declared_value, insure, expected DeclaredValue]
            ['3000.00', '0', false, '3000'],      // UI COD without Insure: the COD amount, not ₱500
            ['3000.00', '1000.00', true, '3000'], // a declared value below the COD amount is raised to it
            ['3000.00', '5000.00', true, '5000'], // a higher declared value stands
            ['300.00', '0', false, '500'],        // the ₱500 floor still applies
        ];

        $this->setUpLbc();
        $this->fakeLbc();

        foreach ($cases as [$cod, $declared, $insure]) {
            $order = $this->order([], ['payment_method' => 'cod', 'total' => $cod, 'cod_amount' => $cod, 'declared_value' => $declared]);
            $this->assertTrue($this->lbc()->createShipment($order, $this->bookingOptions(['insure' => $insure]))['success']);
        }

        $sent = Http::recorded(fn (Request $r) => self::isPickupRequest($r))
            ->map(fn (array $pair) => simplexml_load_string($pair[0]->body()))->values();
        $this->assertCount(count($cases), $sent);
        foreach ($cases as $i => [$cod, $declared, , $expected]) {
            $this->assertSame((string) (float) $cod, (string) $sent[$i]->CODAmount);
            $this->assertSame($expected, (string) $sent[$i]->DeclaredValue, "COD {$cod}, declared {$declared}");
        }
    }

    public function test_lbc_prepaid_declared_value(): void
    {
        $cases = [
            // [declared_value, insure, expected DeclaredValue]
            ['0', false, '0'],          // prepaid and uninsured: nothing declared
            ['2000.00', false, '0'],    // a declared value alone does not insure
            ['2000.00', true, '2000'],  // insured: the declared value
            ['100.00', true, '500'],    // insured: the ₱500 floor
        ];

        $this->setUpLbc();
        $this->fakeLbc();

        foreach ($cases as [$declared, $insure]) {
            $order = $this->order([], ['payment_method' => 'prepaid', 'total' => '2000.00', 'cod_amount' => null, 'declared_value' => $declared]);
            $this->assertTrue($this->lbc()->createShipment($order, $this->bookingOptions(['insure' => $insure]))['success']);
        }

        $sent = Http::recorded(fn (Request $r) => self::isPickupRequest($r))
            ->map(fn (array $pair) => simplexml_load_string($pair[0]->body()))->values();
        $this->assertCount(count($cases), $sent);
        foreach ($cases as $i => [$declared, $insure, $expected]) {
            $this->assertSame('0', (string) $sent[$i]->CODAmount);
            $this->assertSame($expected, (string) $sent[$i]->DeclaredValue, "declared {$declared}, insure ".var_export($insure, true));
        }
    }

    public function test_lbc_pickup_date_used_when_no_slot(): void
    {
        $this->setUpLbc();
        $this->fakeLbc();

        $shipment = $this->shipment([], ['options' => ['pickup_date' => '2026-11-07', 'parcel' => ['type' => '16', 'weight_kg' => 1.2, 'length_cm' => 20, 'width_cm' => 15, 'height_cm' => 10]]]);
        $opts = CourierOptions::for($shipment) + ['pickup' => $this->pickupPoint(), 'service' => 'REGULAR'];
        $this->assertArrayNotHasKey('pickup_slot', $opts);

        $result = $this->lbc()->createShipment(CourierOrder::fromApi($shipment), $opts);

        $this->assertTrue($result['success']);
        $this->assertSame('11/07/2026 10:00:00', $this->sentPickupFields()['PreferredDate']);
        $this->assertSame('2026-11-07', $result['meta']['pickup_date']);
    }

    // --- helpers ---------------------------------------------------------------

    private function quadxShipment(CourierAccount $account, array $attributes = []): Shipment
    {
        return Shipment::factory()->create($attributes + [
            'courier_account_id' => $account->id,
            'courier' => 'quadx',
            'courier_ref' => 'VS000001',
            'payment_method' => 'prepaid',
            'recipient' => $this->recipient(),
            'pickup' => $this->pickupArray(),
            'items' => [['name' => 'Blue Tee', 'quantity' => 1]],
            'total' => '500.00',
        ]);
    }

    private function bookQuadx(Shipment $shipment): array
    {
        return CourierRegistry::for($shipment->courierAccount)
            ->createShipment(CourierOrder::fromApi($shipment), CourierOptions::for($shipment));
    }

    /** @return list<array<string, mixed>> */
    private function quadxBodies(): array
    {
        return Http::recorded(fn (Request $r) => $r->url() === self::QUADX_CREATE)
            ->map(fn (array $pair) => $pair[0]->data())->values()->all();
    }

    private function spxShipment(CourierAccount $account, array $options, array $attributes = []): Shipment
    {
        return Shipment::factory()->create($attributes + [
            'courier_account_id' => $account->id,
            'courier' => 'spx',
            'courier_ref' => 'VS000001',
            'payment_method' => 'prepaid',
            'recipient' => $this->recipient(),
            'pickup' => $this->pickupArray(),
            'options' => $options,
            'items' => [['name' => 'Blue Tee', 'quantity' => 1]],
            'total' => '500.00',
        ]);
    }

    private function bookSpx(CourierAccount $account, Shipment $shipment): array
    {
        $courier = new SpxFulfillment($account->fresh(), AccountSettings::for($account), sleeper: static function (int $ms): void {});

        return $courier->createShipment(CourierOrder::fromApi($shipment), CourierOptions::for($shipment));
    }

    private function fakeSpx(): void
    {
        Http::fake([
            '*batch_create_order*' => Http::response(['ret_code' => 0, 'message' => 'success.', 'data' => ['batch_no' => 1]]),
            '*get_order_create_result*' => Http::response(['ret_code' => 0, 'message' => 'success.', 'data' => [
                'batch_no' => 1, 'task_status' => 4, 'description' => 'Done', 'total_count' => 1, 'success_count' => 1,
                'fail_count' => 0, 'progress' => '100.00%',
                'orders' => [['order_id' => '1', 'tracking_no' => 'SPEPH000000000001', 'estimated_shipping_fee' => 25]],
                'fail_list' => [],
            ]]),
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function spxParcels(): array
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), 'batch_create_order'))
            ->map(fn (array $pair) => $pair[0]->data()['orders'][0]['parcel_info'])->values()->all();
    }

    private function recipient(): array
    {
        return [
            'first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'phone' => '09171234567',
            'address_1' => '12 Mabini St', 'barangay' => 'Lahug (Pob.)', 'city' => 'Cebu City', 'state' => 'Cebu',
            'postcode' => '6000', 'country' => 'PH',
        ];
    }

    private function pickupArray(): array
    {
        return [
            'contact_name' => 'Juan Shop', 'contact_phone' => '09181234567', 'address_1' => '5 Shaw Blvd',
            'barangay' => 'Wack-wack Greenhills', 'city' => 'Mandaluyong', 'state' => 'Metro Manila', 'postcode' => '1550', 'country' => 'PH',
        ];
    }
}
