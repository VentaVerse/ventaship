<?php

namespace Tests\Feature\Api;

use App\Couriers\CourierRegistry;
use App\Couriers\FakeCourier;
use App\Models\ClientApp;
use App\Models\CourierAccount;
use App\Models\CourierCall;
use App\Models\OutgoingEvent;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsShipments;
use Tests\Concerns\SignsApiRequests;
use Tests\TestCase;

class ShipmentQueriesTest extends TestCase
{
    use BuildsShipments, RefreshDatabase, SignsApiRequests;

    private array $client;

    private CourierAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = $this->makeClient();
        $this->account = CourierAccount::factory()->create(['courier' => 'quadx', 'status' => 'active']);
    }

    public function test_list_by_ids_and_cursor(): void
    {
        $mine = collect(range(1, 55))->map(fn ($i) => $this->shipment(['client_reference' => "R{$i}"]));
        $other = Shipment::factory()->create(['client_app_id' => ClientApp::factory()->create()->id]);

        $first = $this->get('/api/v1/shipments')->assertOk();
        $this->assertCount(50, $first->json('data'));
        $this->assertSame($mine->last()->public_id, $first->json('data.0.id'), 'newest first');
        $this->assertArrayNotHasKey('recipient', $first->json('data.0'));
        $cursor = $first->json('meta.next_cursor');
        $this->assertNotNull($cursor);

        $second = $this->get('/api/v1/shipments?cursor='.$cursor)->assertOk();
        $this->assertCount(5, $second->json('data'));
        $this->assertNull($second->json('meta.next_cursor'));
        $all = array_merge(array_column($first->json('data'), 'id'), array_column($second->json('data'), 'id'));
        $this->assertEqualsCanonicalizing($mine->pluck('public_id')->all(), $all);

        $ids = implode(',', [$mine[0]->public_id, $mine[3]->public_id, $other->public_id]);
        $byIds = $this->get('/api/v1/shipments?ids='.$ids)->assertOk();
        $this->assertEqualsCanonicalizing([$mine[0]->public_id, $mine[3]->public_id], array_column($byIds->json('data'), 'id'));
    }

    public function test_list_filters(): void
    {
        $booked = $this->shipment(['client_reference' => 'A1', 'state' => 'booked']);
        $attention = $this->shipment(['client_reference' => 'A2', 'state' => 'unknown', 'needs_attention' => true]);
        $old = $this->shipment(['client_reference' => 'A3']);
        Shipment::query()->whereKey($old->id)->toBase()->update(['updated_at' => now()->subDays(2)]);

        $this->assertListed('client_reference=A1', [$booked]);
        $this->assertListed('state=unknown', [$attention]);
        $this->assertListed('needs_attention=true', [$attention]);
        $this->assertListed('needs_attention=false', [$booked, $old]);
        $this->assertListed('updated_since='.urlencode(now()->subDay()->toIso8601String()), [$booked, $attention]);
    }

    public function test_list_validates_its_filters(): void
    {
        $tooMany = implode(',', array_map(fn ($i) => 'shp_'.Str::ulid(), range(1, 51)));

        $this->get('/api/v1/shipments?ids='.$tooMany)->assertStatus(422)->assertJsonPath('code', 'invalid_request');
        $this->get('/api/v1/shipments?state=lost')->assertStatus(422);
        $this->get('/api/v1/shipments?needs_attention=maybe')->assertStatus(422);
        $this->get('/api/v1/shipments?updated_since=yesterday-ish')->assertStatus(422);
    }

    public function test_events_timeline(): void
    {
        $s = $this->shipment(['state' => 'booked', 'tracking_number' => 'QX-1']);
        ShipmentEvent::query()->create([
            'shipment_id' => $s->id, 'source' => 'poll', 'courier_status' => 'in_transit', 'label' => 'In transit',
            'phase' => 'in_transit', 'occurred_at' => now()->subHour(), 'raw' => ['secret' => 'x'],
        ]);
        ShipmentEvent::query()->create([
            'shipment_id' => $s->id, 'source' => 'system', 'courier_status' => 'pending', 'label' => 'Pending',
            'phase' => 'to_handover', 'occurred_at' => now()->subDay(),
        ]);

        $res = $this->get("/api/v1/shipments/{$s->public_id}/events")->assertOk();

        $this->assertSame(['pending', 'in_transit'], array_column($res->json('data'), 'courier_status'));
        $this->assertSame('system', $res->json('data.0.source'));
        $this->assertMatchesRegularExpression('/\+08:00$/', $res->json('data.1.occurred_at'));
        $this->assertArrayNotHasKey('raw', $res->json('data.1'));

        $foreign = Shipment::factory()->create(['client_app_id' => ClientApp::factory()->create()->id]);
        $this->get("/api/v1/shipments/{$foreign->public_id}/events")->assertNotFound();
    }

    public function test_refresh_records_events_and_throttles(): void
    {
        $fake = FakeCourier::make()->tracks([
            ['status' => 'pending', 'at' => now()->subHours(2)->getTimestamp()],
            ['status' => 'in_transit', 'at' => now()->subHour()->getTimestamp(), 'remarks' => 'Left hub'],
        ]);
        CourierRegistry::fake('quadx', $fake);
        $s = $this->shipment(['state' => 'booked', 'tracking_number' => 'QX-1']);

        $res = $this->post("/api/v1/shipments/{$s->public_id}/refresh")->assertOk();

        $res->assertJsonPath('data.id', $s->public_id)
            ->assertJsonPath('data.courier_status', 'in_transit')
            ->assertJsonPath('events.1.courier_status', 'in_transit')
            ->assertJsonPath('events.1.remarks', 'Left hub');
        $this->assertSame(['refresh', 'refresh'], ShipmentEvent::query()->pluck('source')->all());
        $this->assertSame('in_transit', $s->refresh()->courier_status);
        $this->assertSame([['QX-1']], array_column($fake->callsTo('getTracking'), 'args'));
        $this->assertGreaterThan(0, OutgoingEvent::query()->where('type', 'shipment.status_changed')->count());

        // A new timestamp: the same signature twice is refused as a replay.
        $this->post("/api/v1/shipments/{$s->public_id}/refresh", [], ['X-Timestamp' => $this->uniqueTimestamp()])
            ->assertStatus(429)
            ->assertJsonPath('code', 'rate_limited')
            ->assertJsonPath('retryable', true)
            ->assertHeader('Retry-After');
        $this->assertCount(1, $fake->callsTo('getTracking'));
    }

    public function test_refresh_refuses_a_shipment_without_tracking(): void
    {
        $fake = FakeCourier::make();
        CourierRegistry::fake('quadx', $fake);
        $s = $this->shipment(['state' => 'queued']);

        $this->post("/api/v1/shipments/{$s->public_id}/refresh")->assertStatus(409)->assertJsonPath('code', 'not_trackable');
        $this->assertSame([], $fake->calls);
    }

    public function test_refresh_courier_failure_is_502_and_not_logged_after(): void
    {
        CourierRegistry::fake('quadx', FakeCourier::make()->throws(new \RuntimeException('down'), 'getTracking'));
        $s = $this->shipment(['state' => 'booked', 'tracking_number' => 'QX-1']);

        $this->post("/api/v1/shipments/{$s->public_id}/refresh")
            ->assertStatus(502)
            ->assertJsonPath('code', 'courier_unavailable')
            ->assertJsonPath('retryable', true);
        $this->assertSame(0, CourierCall::query()->count());
    }

    public function test_wait_returns_booked_state(): void
    {
        config(['queue.connections.couriers' => ['driver' => 'sync']]);
        Sleep::fake();
        $account = $this->fakeDefaultAccount();
        CourierRegistry::fake('quadx', FakeCourier::make()->booksAs('QX-WAIT'));

        $res = $this->signedJson('POST', '/api/v1/shipments?wait=20', $this->shipmentBody(['account_id' => $account->public_id]), [
            'Idempotency-Key' => (string) Str::uuid(),
            'X-Timestamp' => $this->uniqueTimestamp(),
        ], $this->client);

        $res->assertStatus(202)
            ->assertJsonPath('data.state', 'booked')
            ->assertJsonPath('data.tracking_number', 'QX-WAIT');
    }

    public function test_wait_gives_up_after_its_limit_and_clamps_to_twenty_seconds(): void
    {
        config(['queue.connections.couriers' => ['driver' => 'null']]);
        Sleep::fake();
        $this->fakeDefaultAccount();

        $res = $this->signedJson('POST', '/api/v1/shipments?wait=999', $this->shipmentBody(), [
            'Idempotency-Key' => (string) Str::uuid(),
            'X-Timestamp' => $this->uniqueTimestamp(),
        ], $this->client);

        $res->assertStatus(202)->assertJsonPath('data.state', 'queued');
        Sleep::assertSleptTimes(40);
    }

    public function test_submit_is_throttled_like_create(): void
    {
        $route = Route::getRoutes()->match(request()->create('/api/v1/shipments/shp_x/submit', 'POST'));

        $this->assertContains('throttle:api-create', $route->gatherMiddleware());
    }

    private function assertListed(string $query, array $expected): void
    {
        $res = $this->get('/api/v1/shipments?'.$query)->assertOk();
        $this->assertEqualsCanonicalizing(
            array_map(fn (Shipment $s) => $s->public_id, $expected),
            array_column($res->json('data'), 'id'),
            $query,
        );
    }

    private function shipment(array $attributes = []): Shipment
    {
        $reference = $attributes['client_reference'] ?? 'R'.Str::random(8);

        return Shipment::factory()->create($attributes + [
            'client_app_id' => $this->client['app']->id,
            'courier_account_id' => $this->account->id,
            'client_reference' => $reference,
            'active_reference' => $reference,
        ]);
    }

    public function get($uri, array $headers = [])
    {
        return $this->signedJson('GET', $uri, [], $headers + ['X-Timestamp' => $this->uniqueTimestamp()], $this->client);
    }

    public function post($uri, array $data = [], array $headers = [])
    {
        return $this->signedJson('POST', $uri, $data, $headers + ['X-Timestamp' => $this->uniqueTimestamp()], $this->client);
    }
}
