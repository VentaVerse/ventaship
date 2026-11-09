<?php

namespace Tests\Feature\Addresses;

use App\Addresses\PickupAddresses;
use App\Models\PickupAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PickupAddressesTest extends TestCase
{
    use RefreshDatabase;

    private PickupAddresses $addresses;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->addresses = app(PickupAddresses::class);
    }

    private function data(array $over = []): array
    {
        return $over + [
            'label' => 'Warehouse', 'contact_name' => 'Venta Warehouse', 'contact_phone' => '0918 123 4567',
            'address_1' => '5 Shaw Blvd', 'address_2' => '', 'barangay' => 'Wack-Wack', 'district' => '',
            'city' => 'Mandaluyong', 'state' => 'Metro Manila', 'postcode' => '1550',
            'codes' => ['state' => '1300000000', 'city' => '1380500000', 'barangay' => '1380500020'],
        ];
    }

    public function test_default_is_null_without_addresses(): void
    {
        $this->assertNull($this->addresses->default());
    }

    public function test_create_stores_the_shipment_pickup_shape_and_first_one_is_default(): void
    {
        $a = $this->addresses->create($this->data());

        $this->assertStringStartsWith('pad_', $a->public_id);
        $this->assertSame(30, strlen($a->public_id));
        $this->assertSame('public_id', $a->getRouteKeyName());
        $this->assertTrue($a->is_default);
        $this->assertSame('Warehouse', $a->label);
        $this->assertSame([
            'contact_name' => 'Venta Warehouse', 'contact_phone' => '09181234567', 'address_1' => '5 Shaw Blvd',
            'address_2' => null, 'barangay' => 'Wack-Wack', 'district' => null, 'city' => 'Mandaluyong',
            'state' => 'Metro Manila', 'postcode' => '1550', 'country' => 'PH',
            'codes' => ['state' => '1300000000', 'city' => '1380500000', 'barangay' => '1380500020'],
        ], $a->toPickup());
        $this->assertTrue($this->addresses->default()->is($a));

        // The address is encrypted at rest, like a shipment's pickup.
        $raw = (string) DB::table('pickup_addresses')->value('address');
        $this->assertStringNotContainsString('Shaw', $raw);
    }

    public function test_second_address_is_not_default_unless_asked(): void
    {
        $first = $this->addresses->create($this->data());
        $second = $this->addresses->create($this->data(['label' => 'Store']));
        $this->assertFalse($second->is_default);
        $this->assertTrue($this->addresses->default()->is($first));

        $third = $this->addresses->create($this->data(['label' => 'Office', 'is_default' => true]));
        $this->assertTrue($third->fresh()->is_default);
        $this->assertFalse($first->fresh()->is_default);
        $this->assertSame(1, PickupAddress::query()->where('is_default', true)->count());
    }

    public function test_update_changes_fields(): void
    {
        $a = $this->addresses->create($this->data());
        $this->addresses->update($a, $this->data(['label' => 'Main', 'city' => 'Pasig', 'codes' => []]));

        $a = $a->fresh();
        $this->assertSame('Main', $a->label);
        $this->assertSame('Pasig', $a->toPickup()['city']);
        $this->assertSame([], $a->toPickup()['codes']);
        $this->assertTrue($a->is_default);
    }

    public function test_make_default_moves_the_flag(): void
    {
        $first = $this->addresses->create($this->data());
        $second = $this->addresses->create($this->data(['label' => 'Store']));

        $this->addresses->makeDefault($second);

        $this->assertFalse($first->fresh()->is_default);
        $this->assertTrue($second->fresh()->is_default);
        $this->assertTrue($this->addresses->default()->is($second));
    }

    public function test_deleting_the_default_promotes_the_next_one(): void
    {
        $first = $this->addresses->create($this->data());
        $second = $this->addresses->create($this->data(['label' => 'Store']));
        $third = $this->addresses->create($this->data(['label' => 'Office']));

        $this->addresses->delete($first);

        $this->assertNull(PickupAddress::query()->find($first->id));
        $this->assertTrue($second->fresh()->is_default);
        $this->assertFalse($third->fresh()->is_default);

        // Deleting a non-default leaves the default alone.
        $this->addresses->delete($third);
        $this->assertTrue($second->fresh()->is_default);

        $this->addresses->delete($second);
        $this->assertNull($this->addresses->default());
    }

}
