<?php

namespace Tests\Feature\Couriers;

use App\Couriers\AccountSettings;
use App\Couriers\CourierOrder;
use App\Couriers\CourierRegistry;
use App\Couriers\PickupPoint;
use App\Couriers\QuadX\QuadXFulfillment;
use App\Models\CourierAccount;
use App\Models\Shipment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsCourierAccounts;
use Tests\TestCase;

class QuadXFulfillmentTest extends TestCase
{
    use BuildsCourierAccounts, RefreshDatabase;

    public function test_create_shipment_posts_reference_and_recipient(): void
    {
        Http::fake(['api.staging.quadx.xyz/*' => Http::response($this->quadxFixture('order-created'))]);

        $account = $this->quadxAccount('qx-key-1234', 'qx-secret-5678');
        $shipment = $this->shipmentFor($account);

        $result = CourierRegistry::for($account)->createShipment(CourierOrder::fromApi($shipment), [
            'pickup' => $this->pickup(),
            'service' => 'next_day',
            'parcel' => 'box',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('QX-TEST-0001', $result['tracking_number']);
        $this->assertSame('for_pickup', $result['status']);
        $this->assertSame('staging', $result['meta']['mode']);
        $this->assertSame('VS000001', $result['meta']['reference_id']);

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) {
            $this->assertSame('POST', $request->method());
            $this->assertSame('https://api.staging.quadx.xyz/v2/orders', $request->url());
            $this->assertSame('VS000001', $request['reference_id']);
            $this->assertSame('Ñora Dela Cruz', $request['buyer_name']);
            $this->assertSame('Ñora Dela Cruz', $request['delivery_address']['name']);
            $this->assertSame('Makati City', $request['delivery_address']['city']);
            $this->assertSame('Juan Shop', $request['pickup_address']['name']);
            $this->assertSame('0', (string) $request['declared_value']);

            $auth = $request->header('Authorization')[0] ?? '';
            $this->assertMatchesRegularExpression('/^Bearer [A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', $auth);
            [$header, $payload, $sig] = explode('.', substr($auth, 7));
            $claims = json_decode($this->b64urlDecode($payload), true);
            $this->assertSame('qx-key-1234', $claims['sub']);
            $this->assertSame(
                $sig,
                rtrim(strtr(base64_encode(hash_hmac('sha256', "$header.$payload", 'qx-secret-5678', true)), '+/', '-_'), '='),
            );

            return true;
        });
    }

    public function test_production_account_uses_production_host_and_its_own_keys(): void
    {
        Http::fake(['api.quadx.xyz/*' => Http::response($this->quadxFixture('order-created'))]);

        $account = $this->quadxAccount('prod-key-0001', 'prod-secret-0001', ['mode' => 'production']);

        $result = CourierRegistry::for($account)->createShipment(
            CourierOrder::fromApi($this->shipmentFor($account)),
            ['pickup' => $this->pickup()],
        );

        $this->assertTrue($result['success']);
        $this->assertSame('production', $result['meta']['mode']);
        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://api.quadx.xyz/v2/'));
    }

    public function test_create_shipment_without_pickup_sends_nothing(): void
    {
        Http::fake();
        $account = $this->quadxAccount();

        $result = CourierRegistry::for($account)->createShipment(CourierOrder::fromApi($this->shipmentFor($account)), []);

        $this->assertFalse($result['success']);
        Http::assertNothingSent();
    }

    public function test_unconfigured_account_reports_missing_fields_and_sends_nothing(): void
    {
        Http::fake();
        $account = CourierAccount::factory()->create(['courier' => 'quadx']);

        $courier = CourierRegistry::for($account);

        $this->assertInstanceOf(QuadXFulfillment::class, $courier);
        $this->assertFalse($courier->isEnabled());
        $this->assertSame(['API Key', 'Secret Key'], $courier->configurationIssues());
        $this->assertFalse($courier->testConnection()['ok']);
        Http::assertNothingSent();
    }

    public function test_cancel_refused_when_moving(): void
    {
        Http::fake(['api.staging.quadx.xyz/*' => Http::response($this->quadxFixture('cancel-refused'), 422)]);

        $result = CourierRegistry::for($this->quadxAccount())->cancelShipment('QX-TEST-0001');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('already been picked up', $result['error']);
        $this->assertStringContainsString('already moving', $result['error']);
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT'
            && $r->url() === 'https://api.staging.quadx.xyz/v2/orders/QX-TEST-0001/cancel');
    }

    public function test_print_label_returns_provider_url(): void
    {
        $fixture = $this->quadxFixture('print-awb');
        Http::fake(['api.staging.quadx.xyz/*' => Http::response($fixture)]);

        $result = CourierRegistry::for($this->quadxAccount())->printLabel('QX-TEST-0001', 'zebra');

        $this->assertTrue($result['success']);
        $this->assertSame($fixture['message']['awb'], $result['url']);
        $this->assertSame($fixture['message']['v35'], $result['manifest_url']);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.staging.quadx.xyz/v2/print/awb/QX-TEST-0001?type=zebra');
    }

    public function test_tracking_returns_status_and_timeline(): void
    {
        Http::fake(['api.staging.quadx.xyz/*' => Http::response($this->quadxFixture('order-in-transit'))]);

        $result = CourierRegistry::for($this->quadxAccount())->getTracking('QX-TEST-0001');

        $this->assertTrue($result['success']);
        $this->assertSame('in_transit', $result['status']);
        $this->assertSame(['for_pickup', 'picked_up', 'in_transit'], array_column($result['timeline'], 'status'));
    }

    public function test_default_service_and_parcel_come_from_the_account(): void
    {
        Http::fake(['api.staging.quadx.xyz/*' => Http::response($this->quadxFixture('order-created'))]);
        $account = $this->quadxAccount();
        AccountSettings::for($account)->write('quadx_default_service', 'same_day_pickup');
        AccountSettings::for($account)->write('quadx_default_parcel', 'small-pouch');

        CourierRegistry::for($account)->createShipment(CourierOrder::fromApi($this->shipmentFor($account)), ['pickup' => $this->pickup()]);

        Http::assertSent(fn (Request $r) => $r['service'] === 'same_day_pickup' && $r['shipment'] === 'small-pouch');
    }

    public function test_serviceability_refusal_and_success(): void
    {
        Http::fakeSequence('api.staging.quadx.xyz/*')
            ->push($this->quadxFixture('rates'))
            ->push($this->quadxFixture('not-serviceable'), 422);
        $account = $this->quadxAccount();
        $courier = CourierRegistry::for($account);
        $order = CourierOrder::fromApi($this->shipmentFor($account));

        $ok = $courier->checkServiceability($order, $this->pickup());
        $refused = $courier->checkServiceability($order, $this->pickup());

        $this->assertTrue($ok->isServiceable());
        $this->assertSame(145.0, $ok->fees['box']);
        $this->assertTrue($refused->isRefusal());
        $this->assertSame('Delivery address is not serviceable.', $refused->reason);
    }

    public function test_cod_collects_the_cod_amount_not_the_total(): void
    {
        Http::fake(['api.staging.quadx.xyz/*' => Http::response($this->quadxFixture('order-created'))]);
        $account = $this->quadxAccount();
        $shipment = $this->shipmentFor($account, ['payment_method' => 'cod', 'total' => 1500.00, 'cod_amount' => 1290.00]);

        $result = CourierRegistry::for($account)->createShipment(CourierOrder::fromApi($shipment), ['pickup' => $this->pickup()]);

        $this->assertTrue($result['success']);
        Http::assertSent(fn (Request $r) => $r['payment_method'] === 'cod' && (float) $r['total'] === 1290.0);
        Http::assertNotSent(fn (Request $r) => (float) ($r['total'] ?? 0) === 1500.0);
    }

    public function test_a_cod_shipment_without_an_amount_or_a_contradicting_buyer_payment_is_refused(): void
    {
        Http::fake();
        $account = $this->quadxAccount();
        $courier = CourierRegistry::for($account);

        $noAmount = CourierOrder::fromApi($this->shipmentFor($account, ['payment_method' => 'cod', 'cod_amount' => null]));
        $this->assertStringContainsString('needs a cod_amount', $courier->createShipment($noAmount, ['pickup' => $this->pickup()])['error']);

        $prepaid = CourierOrder::fromApi($this->shipmentFor($account));
        $said = $courier->createShipment($prepaid, ['pickup' => $this->pickup(), 'buyer_payment' => 'cod']);
        $this->assertFalse($said['success']);
        $this->assertStringContainsString('the shipment is prepaid', $said['error']);

        Http::assertNothingSent();
    }

    private function shipmentFor(CourierAccount $account, array $attributes = []): Shipment
    {
        return Shipment::factory()->create($attributes + [
            'courier_account_id' => $account->id,
            'courier_ref' => 'VS000001',
            'payment_method' => 'prepaid',
            'recipient' => [
                'first_name' => 'Ñora',
                'last_name' => 'Dela Cruz',
                'phone' => '09171234567',
                'address_1' => '12 Mabini St',
                'barangay' => 'Poblacion',
                'city' => 'Makati',
                'state' => 'Metro Manila',
                'postcode' => '1210',
                'country' => 'PH',
            ],
            'items' => [['name' => 'Blue Tee', 'quantity' => 2, 'price' => 250]],
            'total' => 500,
        ]);
    }

    private function pickup(): PickupPoint
    {
        return PickupPoint::fromArray([
            'contact_name' => 'Juan Shop',
            'contact_phone' => '09181234567',
            'address_1' => '5 Shaw Blvd',
            'barangay' => 'Wack-Wack',
            'city' => 'Mandaluyong',
            'state' => 'Metro Manila',
            'postcode' => '1550',
            'country' => 'PH',
        ]);
    }

    private function b64urlDecode(string $s): string
    {
        return base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4));
    }
}
