<?php

namespace Tests\Feature\Couriers;

use App\Couriers\CourierOrder;
use App\Couriers\CourierRegistry;
use App\Couriers\FakeCourier;
use App\Couriers\PickupPoint;
use App\Couriers\ServiceabilityService;
use App\Couriers\Support\ServiceabilityVerdict;
use App\Models\CourierAccount;
use App\Models\Shipment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Concerns\BuildsCourierAccounts;
use Tests\TestCase;

class ServiceabilityServiceTest extends TestCase
{
    use BuildsCourierAccounts, RefreshDatabase;

    public function test_definite_verdict_is_cached_per_account_and_address(): void
    {
        Http::fake(['api.staging.quadx.xyz/*' => Http::response($this->quadxFixture('rates'))]);
        $a = $this->quadxAccount();
        $b = $this->quadxAccount();
        $order = CourierOrder::fromApi(Shipment::factory()->create(['recipient' => ['city' => 'Makati', 'state' => 'Metro Manila']]));
        $other = CourierOrder::fromApi(Shipment::factory()->create(['recipient' => ['city' => 'Cebu City', 'state' => 'Cebu']]));

        $this->assertTrue(ServiceabilityService::check($a, $order, $this->pickup())->isServiceable());
        $this->assertTrue(ServiceabilityService::check($a, $order, $this->pickup())->isServiceable());
        Http::assertSentCount(1);

        ServiceabilityService::check($b, $order, $this->pickup());
        ServiceabilityService::check($a, $other, $this->pickup());
        Http::assertSentCount(3);
    }

    public function test_unknown_is_not_cached_and_a_throwing_courier_is_unknown(): void
    {
        $account = CourierAccount::factory()->create();
        $fake = FakeCourier::make()->throws(new RuntimeException('boom'), 'checkServiceability');
        CourierRegistry::fake('quadx', $fake);
        $order = CourierOrder::fromApi(Shipment::factory()->create());

        $first = ServiceabilityService::check($account, $order, $this->pickup());
        ServiceabilityService::check($account, $order, $this->pickup());

        $this->assertSame(ServiceabilityVerdict::UNKNOWN, $first->status);
        $this->assertCount(2, $fake->callsTo('checkServiceability'));
    }

    private function pickup(): PickupPoint
    {
        return PickupPoint::fromArray(['address_1' => '5 Shaw Blvd', 'city' => 'Mandaluyong', 'state' => 'Metro Manila', 'codes' => ['state' => '1300000000']]);
    }
}
