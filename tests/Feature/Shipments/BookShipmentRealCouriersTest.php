<?php

namespace Tests\Feature\Shipments;

use App\Models\CourierAccount;
use App\Models\OutgoingEvent;
use App\Models\Shipment;
use App\Shipments\Jobs\BookShipment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsCourierAccounts;
use Tests\TestCase;

/**
 * The real QuadX and SPX adapters through BookShipment, with only HTTP faked. A create request that may have
 * reached the courier (transport error, timeout, 5xx) must end `unknown` with the reference kept, never `failed`:
 * a freed reference invites the client to book the same parcel a second time.
 */
class BookShipmentRealCouriersTest extends TestCase
{
    use BuildsCourierAccounts, RefreshDatabase;

    private const QUADX_CREATE = 'api.staging.quadx.xyz/v2/orders';

    private const SPX_CREATE = '*batch_create_order*';

    public function test_quadx_connection_error_on_create_is_unknown(): void
    {
        Http::fake([self::QUADX_CREATE => Http::failedConnection('cURL error 28: Operation timed out after 40000 milliseconds')]);
        $s = $this->shipment($this->quadxAccount());

        Bus::dispatchSync(new BookShipment($s->id));

        $this->assertUnknown($s);
    }

    public function test_quadx_503_on_create_is_unknown(): void
    {
        Http::fake([self::QUADX_CREATE => Http::response(['message' => 'Service Unavailable'], 503)]);
        $s = $this->shipment($this->quadxAccount());

        Bus::dispatchSync(new BookShipment($s->id));

        $this->assertUnknown($s);
    }

    public function test_quadx_422_refusal_still_fails(): void
    {
        Http::fake([self::QUADX_CREATE => Http::response(['errors' => ['detail' => 'Invalid delivery barangay.']], 422)]);
        $s = $this->shipment($this->quadxAccount());

        Bus::dispatchSync(new BookShipment($s->id));

        $s->refresh();
        $this->assertSame('failed', $s->state);
        $this->assertSame('courier_refused', $s->error_code);
        $this->assertStringContainsString('Invalid delivery barangay.', $s->error_message);
        $this->assertNull($s->active_reference);
        $this->assertSame('shipment.booking_failed', OutgoingEvent::query()->sole()->type);
    }

    public function test_spx_connection_error_on_create_is_unknown(): void
    {
        Http::fake([self::SPX_CREATE => Http::failedConnection('cURL error 28: Operation timed out after 40000 milliseconds')]);
        $s = $this->shipment($this->spxAccount(), ['courier' => 'spx']);

        Bus::dispatchSync(new BookShipment($s->id));

        $this->assertUnknown($s);
    }

    public function test_spx_503_on_create_is_unknown(): void
    {
        Http::fake([self::SPX_CREATE => Http::response('<html>503 Service Unavailable</html>', 503)]);
        $s = $this->shipment($this->spxAccount(), ['courier' => 'spx']);

        Bus::dispatchSync(new BookShipment($s->id));

        $this->assertUnknown($s);
    }

    public function test_spx_5xx_with_an_error_envelope_is_unknown(): void
    {
        Http::fake([self::SPX_CREATE => Http::response(['ret_code' => 10000, 'message' => 'system error', 'data' => null], 502)]);
        $s = $this->shipment($this->spxAccount(), ['courier' => 'spx']);

        Bus::dispatchSync(new BookShipment($s->id));

        $this->assertUnknown($s);
    }

    public function test_spx_api_refusal_still_fails(): void
    {
        Http::fake([self::SPX_CREATE => Http::response(['ret_code' => 11001, 'message' => 'invalid deliver_post_code', 'data' => null])]);
        $s = $this->shipment($this->spxAccount(), ['courier' => 'spx']);

        Bus::dispatchSync(new BookShipment($s->id));

        $s->refresh();
        $this->assertSame('failed', $s->state);
        $this->assertSame('courier_refused', $s->error_code);
        $this->assertNull($s->active_reference);
    }

    private function assertUnknown(Shipment $s): void
    {
        $s->refresh();
        $this->assertSame('unknown', $s->state);
        $this->assertSame('4211', $s->active_reference, 'reference kept');
        $this->assertTrue($s->needs_attention);
        $this->assertSame('Booking outcome unknown — check the courier portal before retrying', $s->attention_reason);
        $this->assertNull($s->error_code);
        $this->assertSame(['shipment.unknown'], OutgoingEvent::query()->pluck('type')->all());
        $this->assertCount(1, Http::recorded(), 'sent once, never retried');
    }

    private function shipment(CourierAccount $account, array $attributes = []): Shipment
    {
        return Shipment::factory()->create($attributes + [
            'courier_account_id' => $account->id,
            'courier' => $account->courier,
            'client_reference' => '4211',
            'active_reference' => '4211',
            'courier_ref' => 'VS000001',
            'state' => 'queued',
            'service' => 'next_day',
            'payment_method' => 'prepaid',
            'pickup' => [
                'contact_name' => 'Juan Shop', 'contact_phone' => '09181234567', 'address_1' => '5 Shaw Blvd',
                'barangay' => 'Wack-Wack', 'city' => 'Mandaluyong', 'state' => 'Metro Manila', 'postcode' => '1550',
                'country' => 'PH', 'codes' => [],
            ],
            'recipient' => [
                'first_name' => 'Jane', 'last_name' => 'Doe', 'phone' => '09171234567', 'address_1' => '123 Mango St',
                'barangay' => 'Lahug', 'city' => 'Cebu City', 'state' => 'Cebu', 'postcode' => '6000', 'country' => 'PH',
                'codes' => [],
            ],
            'items' => [['name' => 'Blue Tee', 'quantity' => 1, 'price' => '500.00']],
            'total' => 500,
        ]);
    }
}
