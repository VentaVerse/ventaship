<?php

namespace Tests\Feature\Shipments;

use App\Couriers\CourierRegistry;
use App\Couriers\FakeCourier;
use App\Models\Shipment;
use App\Shipments\CourierReference;
use App\Shipments\ShipmentCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsShipments;
use Tests\Concerns\CallsClientApi;
use Tests\TestCase;

/** Every new shipment's courier reference: YYYYMMDD-XXXXXX, unique, kept for life. */
class CourierReferenceTest extends TestCase
{
    use BuildsShipments, CallsClientApi, RefreshDatabase;

    protected function tearDown(): void
    {
        CourierReference::fakeSuffixes(null);
        parent::tearDown();
    }

    public function test_the_format_is_the_manila_date_and_six_characters(): void
    {
        $this->travelTo(now('Asia/Manila')->setDate(2026, 11, 12)->setTime(23, 30));

        $this->assertMatchesRegularExpression('/^20261112-[A-Z0-9]{6}$/', CourierReference::generate());
        $this->assertMatchesRegularExpression(CourierReference::PATTERN, CourierReference::generate());
    }

    public function test_a_clash_is_generated_again(): void
    {
        $this->travelTo(now('Asia/Manila')->setDate(2026, 11, 12)->setTime(9, 0));
        Shipment::factory()->create(['courier_ref' => '20261112-AAAAAA']);
        $suffixes = ['AAAAAA', 'AAAAAA', 'BBBBBB'];
        CourierReference::fakeSuffixes(function () use (&$suffixes) {
            return array_shift($suffixes);
        });

        $this->assertSame('20261112-BBBBBB', CourierReference::generate());
    }

    public function test_api_shipments_get_one_and_keep_their_order_number(): void
    {
        $client = $this->makeClient();
        $this->fakeDefaultAccount();

        $s = app(ShipmentCreator::class)->create($client['app'], $this->shipmentBody(['submit' => false]));

        $this->assertMatchesRegularExpression(CourierReference::PATTERN, $s->courier_ref);
        $this->assertSame('4211', $s->client_reference);
    }

    public function test_edits_retry_and_rebooking_reuse_it(): void
    {
        $this->fakeDefaultAccount();
        $fake = FakeCourier::make('quadx')->refusesWith('No');
        CourierRegistry::fake('quadx', $fake);
        $creator = app(ShipmentCreator::class);

        $s = $creator->create(null, $this->shipmentBody(['courier' => 'quadx', 'client_reference' => 'R-1']), 'manual', now: true);
        $ref = $s->courier_ref;
        $this->assertSame('failed', $s->state);

        $creator->update($s, $this->shipmentBody(['courier' => 'quadx', 'client_reference' => 'R-2']));
        $this->assertSame($ref, $s->refresh()->courier_ref);

        CourierRegistry::fake('quadx', FakeCourier::make('quadx')->booksAs('QX-9'));
        $booked = $creator->submit($s->refresh(), now: true);
        $this->assertSame('booked', $booked->state);
        $this->assertSame($ref, $booked->courier_ref);
        $this->assertSame($ref, CourierRegistry::for($booked->courierAccount)->calls[0]['args'][0]->reference);
    }
}
