<?php

namespace Tests\Feature\Api;

use App\Http\Resources\ShipmentResource;
use App\Models\Shipment;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsShipments;
use Tests\Concerns\CallsClientApi;
use Tests\TestCase;

/** `pickup_slot_label` / `pickup_date` on the shipment resource, and the `pickup_slot_unavailable` 422 code. */
class PickupSlotFieldsTest extends TestCase
{
    use BuildsShipments, CallsClientApi, RefreshDatabase;

    private array $client;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->travelTo(CarbonImmutable::parse('2026-11-09 10:00:00', 'Asia/Manila'));
        $this->client = $this->makeClient();
    }

    /** An SPX slot as POST /pickup-slots hands it out: Tue Nov 10 (Manila), 08:00-12:00. */
    private function spxSlot(string $day = '2026-11-10 00:00:00'): string
    {
        return (string) json_encode([
            'pickup_time' => CarbonImmutable::parse($day, 'Asia/Manila')->getTimestamp(),
            'pickup_time_range_id' => 2,
            'pickup_time_range' => '08:00-12:00',
        ]);
    }

    public function test_spx_create_answers_the_slot_label_and_its_manila_day(): void
    {
        $this->fakeDefaultAccount('spx');

        $res = $this->create($this->spxBody(['pickup_slot' => $this->spxSlot()]))->assertStatus(202);

        $res->assertJsonPath('data.pickup_slot', $this->spxSlot())
            ->assertJsonPath('data.pickup_slot_label', 'Tue, Nov 10 · 08:00-12:00')
            ->assertJsonPath('data.pickup_date', '2026-11-10');
        $this->api('GET', '/api/v1/shipments/'.$res->json('data.id'))->assertOk()
            ->assertJsonPath('data.pickup_slot_label', 'Tue, Nov 10 · 08:00-12:00')
            ->assertJsonPath('data.pickup_date', '2026-11-10');
    }

    public function test_spx_slot_day_is_the_manila_day_not_the_utc_one(): void
    {
        // 07:30 Manila on Nov 11 is still Nov 10 in UTC.
        $s = $this->shipment('spx', ['pickup_slot' => $this->spxSlot('2026-11-11 07:30:00')]);

        $data = (new ShipmentResource($s))->resolve();
        $this->assertSame('Wed, Nov 11 · 08:00-12:00', $data['pickup_slot_label']);
        $this->assertSame('2026-11-11', $data['pickup_date']);
    }

    public function test_without_a_slot_both_are_null(): void
    {
        $this->fakeDefaultAccount('spx');

        $res = $this->create($this->spxBody())->assertStatus(202);
        $res->assertJsonPath('data.pickup_slot', null)
            ->assertJsonPath('data.pickup_slot_label', null)
            ->assertJsonPath('data.pickup_date', null);
        $this->assertArrayHasKey('pickup_slot_label', $res->json('data'));

        foreach (['quadx', 'spx', 'lbc'] as $courier) {
            $data = (new ShipmentResource($this->shipment($courier, [])))->resolve();
            $this->assertNull($data['pickup_slot_label'], $courier);
            $this->assertNull($data['pickup_date'], $courier);
        }
    }

    public function test_a_slot_that_is_not_usable_has_no_label(): void
    {
        $cases = [
            ['spx', 'pickup', ['pickup_slot' => 'tomorrow']],
            ['spx', 'dropoff', ['pickup_slot' => $this->spxSlot()]], // SPX ignores a drop-off's slot
            ['lbc', 'pickup', ['pickup_slot' => '2026-02-30']],
            ['quadx', 'pickup', ['pickup_slot' => '2026-11-10']], // QuadX has no slots
        ];
        foreach ($cases as [$courier, $method, $options]) {
            $data = (new ShipmentResource($this->shipment($courier, $options, $method)))->resolve();
            $this->assertNull($data['pickup_slot_label'], "{$courier} {$method}");
            $this->assertNull($data['pickup_date'], "{$courier} {$method}");
            $this->assertSame($options['pickup_slot'], $data['pickup_slot'], 'pickup_slot is still answered as stored');
        }
    }

    public function test_lbc_slot_is_a_date_and_a_sent_pickup_date_is_kept_without_one(): void
    {
        $slot = (new ShipmentResource($this->shipment('lbc', ['pickup_slot' => '2026-11-10', 'pickup_date' => '2026-11-12'])))->resolve();
        $this->assertSame('Tue, Nov 10', $slot['pickup_slot_label']);
        $this->assertSame('2026-11-10', $slot['pickup_date'], 'LBC books on the slot when both are sent');

        $dateOnly = (new ShipmentResource($this->shipment('lbc', ['pickup_date' => '2026-11-12'])))->resolve();
        $this->assertNull($dateOnly['pickup_slot_label']);
        $this->assertSame('2026-11-12', $dateOnly['pickup_date']);
    }

    public function test_a_refused_slot_alone_is_pickup_slot_unavailable(): void
    {
        $this->fakeDefaultAccount('spx');

        $res = $this->create($this->spxBody(['pickup_slot' => 'tomorrow']))->assertStatus(422);

        $res->assertJsonPath('code', 'pickup_slot_unavailable')
            ->assertJsonPath('retryable', false)
            ->assertJsonPath('errors.pickup_slot.0', 'For SPX, the pickup_slot must be a value from POST /pickup-slots.');
        $this->assertSame(['pickup_slot'], array_keys($res->json('errors')));
        $this->assertSame(0, Shipment::query()->count());
    }

    public function test_a_refused_slot_with_other_errors_stays_invalid_request(): void
    {
        $this->fakeDefaultAccount('spx');

        $res = $this->create($this->spxBody(['pickup_slot' => 'tomorrow', 'note' => str_repeat('n', 257)]))->assertStatus(422);

        $res->assertJsonPath('code', 'invalid_request')
            ->assertJsonValidationErrors(['pickup_slot', 'note'], 'errors');

        // And errors without the slot are unchanged.
        $this->create($this->spxBody(['note' => str_repeat('n', 257)]))->assertStatus(422)
            ->assertJsonPath('code', 'invalid_request');
    }

    // ---------------------------------------------------------------------

    private function spxBody(array $over = []): array
    {
        return $this->shipmentBody(['courier' => 'spx', 'client_reference' => Str::random(8), 'parcel' => ['type' => 'small']] + $over);
    }

    private function shipment(string $courier, array $options, string $method = 'pickup'): Shipment
    {
        return Shipment::factory()->create([
            'client_app_id' => $this->client['app']->id,
            'courier' => $courier,
            'method' => $method,
            'options' => $options,
        ]);
    }

    private function create(array $body): TestResponse
    {
        return $this->api('POST', '/api/v1/shipments', $body, ['Idempotency-Key' => (string) Str::uuid()]);
    }

    private function api(string $method, string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->apiJson($method, $uri, $data, $headers, $this->client);
    }
}
