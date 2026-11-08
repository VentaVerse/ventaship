<?php

namespace Tests\Feature\Couriers;

use App\Couriers\CourierOrder;
use App\Couriers\CourierRegistry;
use App\Couriers\FakeCourier;
use App\Couriers\PickupOrigin;
use App\Couriers\PickupPoint;
use App\Couriers\QuadX\QuadXFulfillment;
use App\Models\CourierAccount;
use App\Models\Shipment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class CourierRegistryTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_quadx_is_registered_and_describe_needs_no_database(): void
    {
        $this->assertSame(['quadx'], CourierRegistry::keys());

        $described = CourierRegistry::describe('quadx');
        $this->assertInstanceOf(QuadXFulfillment::class, $described);
        $this->assertNotContains('quadx_enabled', array_column($described->settingsSchema(), 'key'));
        $this->assertNotContains('quadx_mode', array_column($described->settingsSchema(), 'key'));

        $this->expectException(InvalidArgumentException::class);
        CourierRegistry::describe('dhl');
    }

    public function test_fake_answers_for_every_account_of_that_courier(): void
    {
        $fake = FakeCourier::make()->booksAs('QX-FAKE-9', 'for_pickup');
        CourierRegistry::fake('quadx', $fake);
        $shipment = Shipment::factory()->create(['courier_ref' => 'VS000042']);

        $result = CourierRegistry::for(CourierAccount::factory()->create())
            ->createShipment(CourierOrder::fromApi($shipment), []);

        $this->assertSame(['success' => true, 'tracking_number' => 'QX-FAKE-9', 'status' => 'for_pickup', 'meta' => ['fake' => true, 'reference_id' => 'VS000042']], $result);
        $this->assertCount(1, $fake->callsTo('createShipment'));

        CourierRegistry::clearFakes();
        $this->assertInstanceOf(QuadXFulfillment::class, CourierRegistry::for(CourierAccount::factory()->create()));
    }

    public function test_from_api_maps_the_shipment(): void
    {
        $shipment = Shipment::factory()->create([
            'courier_ref' => 'VS000007',
            'payment_method' => 'cod',
            'cod_amount' => '1290.00',
            'declared_value' => '1290.00',
            'total' => '1290.00',
            'courier_note' => 'Call first',
            'options' => ['parcel' => ['weight_kg' => 1.2]],
            'recipient' => ['first_name' => 'Ñora', 'last_name' => 'Dela Cruz', 'phone' => '09171234567', 'address_1' => '12 Mabini St',
                'barangay' => 'Poblacion', 'city' => 'Makati', 'state' => 'Metro Manila', 'postcode' => '1210', 'country' => 'PH',
                'codes' => ['state' => '1300000000']],
            'items' => [['name' => 'Blue Tee', 'quantity' => 2, 'price' => 645]],
        ]);

        $order = CourierOrder::fromApi($shipment);

        $this->assertSame($shipment->id, $order->id);
        $this->assertSame('VS000007', $order->reference);
        $this->assertTrue($order->cod);
        $this->assertSame(1290.0, $order->cod_amount);
        $this->assertSame(1290.0, $order->total);
        $this->assertSame(1.2, $order->weight_kg);
        $this->assertSame('Ñora', $order->shipping_first_name);
        $this->assertSame('1300000000', $order->shipping_state_code);
        $this->assertSame('Call first', $order->courier_comment);
        $this->assertSame('Blue Tee', $order->products[0]->name);
        $this->assertSame(2, $order->products[0]->quantity);
    }

    public function test_pickup_origin_accepts_a_point_or_the_api_array(): void
    {
        $point = PickupOrigin::from(['pickup' => ['contact_name' => ' Juan ', 'city' => 'Pasig', 'codes' => ['city' => '137403000']]]);

        $this->assertInstanceOf(PickupPoint::class, $point);
        $this->assertSame('Juan', $point->contact_name);
        $this->assertSame('137403000', $point->city_code);
        $this->assertSame('PH', $point->country);
        $this->assertSame($point, PickupOrigin::from(['pickup' => $point]));
        $this->assertNull(PickupOrigin::from([]));
        $this->assertNull(PickupOrigin::from(['pickup' => 'somewhere']));
    }
}
