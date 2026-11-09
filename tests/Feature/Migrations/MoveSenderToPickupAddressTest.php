<?php

namespace Tests\Feature\Migrations;

use App\Addresses\PickupAddresses;
use App\Models\PickupAddress;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** The migration that turns the settings sender (pickup.* keys) into a "Main" default pickup address. */
class MoveSenderToPickupAddressTest extends TestCase
{
    use RefreshDatabase;

    private object $migration;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->migration = require database_path('migrations/2026_11_09_000600_move_sender_setting_to_pickup_address.php');
    }

    private function seedSender(array $values): void
    {
        $settings = app(Settings::class);
        foreach ($values as $k => $v) {
            $settings->set("pickup.{$k}", $v);
        }
        $settings->set('shipments.reference_prefix', 'ZZ');
    }

    public function test_a_filled_sender_becomes_the_main_default_address_and_the_keys_go(): void
    {
        $this->seedSender([
            'contact_name' => 'Venta Warehouse', 'contact_phone' => '0918 123 4567', 'address_1' => '5 Shaw Blvd',
            'address_2' => null, 'barangay' => 'Wack-Wack', 'district' => null, 'city' => 'Mandaluyong', 'state' => 'Metro Manila',
            'postcode' => '1550', 'codes.state' => '1300000000', 'codes.city' => '1380500000', 'codes.barangay' => '',
        ]);

        $this->migration->up();

        $a = PickupAddress::query()->sole();
        $this->assertSame('Main', $a->label);
        $this->assertTrue($a->is_default);
        $this->assertStringStartsWith('pad_', $a->public_id);
        $this->assertSame([
            'contact_name' => 'Venta Warehouse', 'contact_phone' => '09181234567', 'address_1' => '5 Shaw Blvd',
            'address_2' => null, 'barangay' => 'Wack-Wack', 'district' => null, 'city' => 'Mandaluyong',
            'state' => 'Metro Manila', 'postcode' => '1550', 'country' => 'PH',
            'codes' => ['state' => '1300000000', 'city' => '1380500000'],
        ], $a->toPickup());
        $this->assertTrue(app(PickupAddresses::class)->default()->is($a));

        $this->assertSame(0, DB::table('settings')->where('key', 'like', 'pickup.%')->count());
        $this->assertSame(1, DB::table('settings')->where('key', 'shipments.reference_prefix')->count());
    }

    public function test_an_address_alone_is_enough(): void
    {
        $this->seedSender(['address_1' => '5 Shaw Blvd', 'city' => 'Mandaluyong']);

        $this->migration->up();

        $this->assertSame('5 Shaw Blvd', PickupAddress::query()->sole()->toPickup()['address_1']);
        $this->assertSame(0, DB::table('settings')->where('key', 'like', 'pickup.%')->count());
    }

    public function test_an_empty_sender_creates_nothing_but_the_keys_still_go(): void
    {
        $this->seedSender(['contact_name' => null, 'address_1' => '', 'city' => 'Makati']);

        $this->migration->up();

        $this->assertSame(0, PickupAddress::query()->count());
        $this->assertSame(0, DB::table('settings')->where('key', 'like', 'pickup.%')->count());
    }

    public function test_an_existing_default_is_kept(): void
    {
        $existing = app(PickupAddresses::class)->create(['label' => 'Store', 'contact_name' => 'A', 'contact_phone' => '09181234567',
            'address_1' => '1 X St', 'city' => 'Makati', 'state' => 'Metro Manila']);
        $this->seedSender(['contact_name' => 'Venta Warehouse', 'address_1' => '5 Shaw Blvd']);

        $this->migration->up();

        $this->assertSame(2, PickupAddress::query()->count());
        $this->assertTrue($existing->fresh()->is_default);
        $this->assertFalse(PickupAddress::query()->where('label', 'Main')->sole()->is_default);
    }
}
