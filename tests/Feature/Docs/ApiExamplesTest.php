<?php

namespace Tests\Feature\Docs;

use App\Api\Signature;
use App\Couriers\CourierOrder;
use App\Couriers\CourierRegistry;
use App\Couriers\FakeCourier;
use App\Http\Resources\ShipmentResource;
use App\Models\CourierAccount;
use App\Models\OutgoingEvent;
use App\Models\Shipment;
use App\Shipments\Jobs\BookShipment;
use App\Webhooks\Outbound\EventPayload;
use App\Webhooks\Outbound\Outbox;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsCourierAccounts;
use Tests\Concerns\SignsApiRequests;
use Tests\TestCase;

/**
 * Keeps docs/api.md honest. Every request example in tests/Fixtures/api/ is sent to the real endpoint and must be
 * accepted; every response example must have exactly the shape (keys, nesting and JSON types) the code answers
 * today; and every example quoted in docs/api.md must be the fixture file, byte for byte as JSON.
 *
 * To regenerate the response fixtures after a deliberate contract change:
 *   VENTASHIP_WRITE_API_FIXTURES=1 php artisan test --filter=ApiExamplesTest
 * then update docs/api.md to match (the last test says which blocks differ).
 */
class ApiExamplesTest extends TestCase
{
    use BuildsCourierAccounts, RefreshDatabase, SignsApiRequests;

    private const TOKEN = 'qx-docs-webhook-token-0123456789abcdefghij';

    /** @var array{app: \App\Models\ClientApp, key: string, secret: string} */
    private array $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-08 09:00:00', 'Asia/Manila'));
        config(['queue.connections.couriers' => ['driver' => 'null']]); // bookings stay queued until a test books them
        $this->client = $this->makeClient('https://shop.example.com/ventaship/webhooks');
    }

    public function test_ping_couriers_and_accounts(): void
    {
        $account = $this->account();

        $this->assertMatchesFixture('ping.response', $this->signed('GET', '/api/v1/ping')->assertOk());
        $this->assertMatchesFixture('couriers.response', $this->signed('GET', '/api/v1/couriers')->assertOk());
        $this->assertMatchesFixture('courier-accounts.response', $this->signed('GET', '/api/v1/courier-accounts')->assertOk());
        $this->assertMatchesFixture('courier-account.response', $this->signed('GET', "/api/v1/courier-accounts/{$account->public_id}")->assertOk());
    }

    public function test_coverage_and_quotes(): void
    {
        $this->account();
        Http::fake(['api.staging.quadx.xyz/v2/orders/estimates/rates' => Http::response($this->quadxFixture('rates'))]);

        $this->assertMatchesFixture('coverage.response', $this->signed('POST', '/api/v1/coverage', $this->fixture('coverage.request'))->assertOk());
        $this->assertMatchesFixture('quotes.response', $this->signed('POST', '/api/v1/quotes', $this->fixture('quotes.request'))->assertOk());
    }

    public function test_pickup_slots(): void
    {
        CourierRegistry::fake('lbc', new class extends FakeCourier
        {
            public function getPickupSlots(CourierOrder $order): array
            {
                return [
                    ['value' => '2026-11-09', 'label' => 'Mon, Nov 9'],
                    ['value' => '2026-11-10', 'label' => 'Tue, Nov 10'],
                ];
            }
        });
        CourierAccount::factory()->create(['courier' => 'lbc', 'label' => 'LBC', 'is_default' => true, 'default_slot' => 'lbc']);

        $this->assertMatchesFixture('pickup-slots.response', $this->signed('POST', '/api/v1/pickup-slots', $this->fixture('pickup-slots.request'))->assertOk());
    }

    public function test_shipment_lifecycle_examples(): void
    {
        $this->account();
        $request = $this->fixture('create-shipment.request');

        // Create: accepted and queued (the booking job has not run yet).
        $created = $this->signed('POST', '/api/v1/shipments', $request, ['Idempotency-Key' => 'order-4211-create'])->assertStatus(202);
        $this->assertSame('queued', $created->json('data.state'));
        $this->assertMatchesFixture('create-shipment.response', $created);
        $id = $created->json('data.id');
        $shipment = Shipment::query()->where('public_id', $id)->sole();

        // Booked by QuadX, then picked up and in transit by webhook.
        $this->travel(2)->seconds();
        Http::fake([
            'api.staging.quadx.xyz/v2/orders' => Http::response($this->quadxFixture('order-created')),
            // What a refresh at 13:05 finds: the three known events plus a newer one.
            'api.staging.quadx.xyz/v2/orders/QX-TEST-0001' => Http::response([
                'tracking_number' => 'QX-TEST-0001',
                'status' => 'out_for_delivery',
                'tat' => ['for_pickup' => 1794099602, 'picked_up' => 1794103200, 'in_transit' => 1794114000, 'out_for_delivery' => 1794114240],
            ]),
        ]);
        Bus::dispatchSync(new BookShipment($shipment->id));
        $this->assertSame('booked', $shipment->refresh()->state);
        $this->travelTo(CarbonImmutable::parse('2026-11-08 13:05:00', 'Asia/Manila'));
        foreach (['webhook-status', 'webhook-in-transit'] as $fixture) {
            $body = (string) file_get_contents(base_path("tests/Fixtures/quadx/{$fixture}.json"));
            $this->call('POST', '/webhooks/quadx?token='.self::TOKEN, [], [], [], ['CONTENT_TYPE' => 'application/json'], $body)->assertOk();
        }

        $this->assertMatchesFixture('shipment.response', $this->signed('GET', "/api/v1/shipments/{$id}")->assertOk());
        $this->assertMatchesFixture('shipment-parties.response', $this->signed('GET', "/api/v1/shipments/{$id}?include=parties")->assertOk());
        $this->assertMatchesFixture('shipments-list.response', $this->signed('GET', '/api/v1/shipments?client_reference=4211')->assertOk());
        $this->assertMatchesFixture('shipment-events.response', $this->signed('GET', "/api/v1/shipments/{$id}/events")->assertOk());
        $this->assertMatchesFixture('refresh.response', $this->signed('POST', "/api/v1/shipments/{$id}/refresh")->assertOk());

        // Outgoing webhook envelopes, exactly as they are posted.
        $events = OutgoingEvent::query()->where('shipment_id', $shipment->id)->orderBy('sequence')->get()->keyBy('type');
        $this->assertMatchesFixture('webhook-shipment-booked', $this->envelope($events['shipment.booked']));
        $statusChanged = OutgoingEvent::query()->where('shipment_id', $shipment->id)->where('type', 'shipment.status_changed')->orderByDesc('sequence')->first();
        $this->assertMatchesFixture('webhook-shipment-status-changed', $this->envelope($statusChanged));
        $ping = DB::transaction(fn () => Outbox::record($this->client['app'], 'ping', ['message' => 'ping']));
        $this->assertMatchesFixture('webhook-ping', $this->envelope($ping));

        // Cancel once out for delivery: refused without calling QuadX, settled at once, so 200.
        $cancel = $this->signed('POST', "/api/v1/shipments/{$id}/cancel", [], ['Idempotency-Key' => 'order-4211-cancel'])->assertOk();
        $this->assertSame('refused', $cancel->json('data.cancel_state'));
        $this->assertMatchesFixture('cancel.response', $cancel);
    }

    public function test_error_examples(): void
    {
        $this->account();

        $invalid = $this->signed('POST', '/api/v1/shipments', $this->fixture('create-shipment-invalid.request'), ['Idempotency-Key' => 'order-4212-create'])
            ->assertStatus(422);
        $this->assertMatchesFixture('error-invalid-request.response', $invalid);

        $this->signed('POST', '/api/v1/shipments', $this->fixture('create-shipment.request'), ['Idempotency-Key' => 'a'])->assertStatus(202);
        $duplicate = $this->signed('POST', '/api/v1/shipments', $this->fixture('create-shipment.request'), ['Idempotency-Key' => 'b'])->assertStatus(409);
        $this->assertMatchesFixture('error-active-shipment-exists.response', $duplicate);

        $unauthorized = $this->signed('GET', '/api/v1/ping', [], ['X-Signature' => 'sha256='.str_repeat('0', 64)])->assertStatus(401);
        $this->assertMatchesFixture('error-unauthorized.response', $unauthorized);
    }

    /** The Shipment resource fields the docs list are exactly the ones ShipmentResource renders. */
    public function test_shipment_fixture_keys_are_the_resource_keys(): void
    {
        $s = Shipment::factory()->create(['client_app_id' => $this->client['app']->id]);

        $plain = array_keys((new ShipmentResource($s))->resolve());
        $parties = array_keys((new ShipmentResource($s))->withParties()->resolve());

        $this->assertSame($plain, array_keys($this->fixture('shipment.response')['data']));
        $this->assertSame($plain, array_keys($this->fixture('create-shipment.response')['data']));
        $this->assertSame($plain, array_keys($this->fixture('cancel.response')['data']));
        $this->assertSame($plain, array_keys($this->fixture('shipments-list.response')['data'][0]));
        $this->assertSame($plain, array_keys($this->fixture('webhook-shipment-booked')['data']['shipment']));
        $this->assertSame($parties, array_keys($this->fixture('shipment-parties.response')['data']));
    }

    /** Every ```json block that docs/api.md marks with `<!-- fixture: name -->` is that fixture, and every fixture is quoted. */
    public function test_docs_quote_the_fixtures(): void
    {
        $doc = (string) file_get_contents(base_path('docs/api.md'));
        preg_match_all('/<!-- fixture: ([a-z0-9.-]+) -->\s*```json\n(.*?)\n```/s', $doc, $blocks, PREG_SET_ORDER);

        $quoted = [];
        foreach ($blocks as [, $name, $json]) {
            $this->assertEquals($this->fixture($name), json_decode($json, true, 512, JSON_THROW_ON_ERROR), "docs/api.md block `{$name}` differs from tests/Fixtures/api/{$name}.json");
            $quoted[] = $name;
        }

        $all = array_map(fn (string $f) => basename($f, '.json'), glob(base_path('tests/Fixtures/api/*.json')));
        $this->assertSame([], array_values(array_diff($all, $quoted)), 'fixtures not quoted in docs/api.md');

        // The signing test vector in §2 is what the server computes.
        $this->assertStringContainsString(
            'X-Signature     '.Signature::sign('test-secret', 1794114300, 'GET', '/api/v1/ping', ''),
            $doc,
        );
    }

    // ---------------------------------------------------------------------

    private function account(): CourierAccount
    {
        return $this->quadxAccount(attributes: [
            'label' => 'QuadX main',
            'is_default' => true,
            'default_slot' => 'quadx',
            'webhook_token' => self::TOKEN,
            'webhook_token_hash' => hash('sha256', self::TOKEN),
        ]);
    }

    private function signed(string $method, string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->signedJson($method, $uri, $data, $headers + ['X-Timestamp' => $this->uniqueTimestamp()], $this->client);
    }

    /** The envelope exactly as DeliverEvent posts it. */
    private function envelope(OutgoingEvent $e): string
    {
        return EventPayload::for($e);
    }

    /** @return array<mixed> */
    private function fixture(string $name): array
    {
        return json_decode((string) file_get_contents(base_path("tests/Fixtures/api/{$name}.json")), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Compares the raw JSON, decoded with objects kept as objects, so `{}` and `[]` are told apart.
     */
    private function assertMatchesFixture(string $name, TestResponse|string $actual): void
    {
        $raw = $actual instanceof TestResponse ? (string) $actual->getContent() : $actual;
        $body = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
        $path = base_path("tests/Fixtures/api/{$name}.json");

        if (getenv('VENTASHIP_WRITE_API_FIXTURES') === '1') {
            file_put_contents($path, json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)."\n");
        }

        $this->assertFileExists($path);
        $this->assertSameShape(json_decode((string) file_get_contents($path), false, 512, JSON_THROW_ON_ERROR), $body, $name);
    }

    /** Same keys in the same order, same nesting, same JSON type at every leaf. Values may differ (ids, times). */
    private function assertSameShape(mixed $expected, mixed $actual, string $path): void
    {
        $this->assertSame(get_debug_type($expected), get_debug_type($actual), "type at {$path}");

        if (is_array($expected)) {
            $this->assertSame($expected === [], $actual === [], "emptiness at {$path}");
            foreach ($expected as $i => $item) {
                $this->assertSameShape($item, $actual[$i] ?? $actual[0], "{$path}[{$i}]");
            }
        } elseif ($expected instanceof \stdClass) {
            $this->assertSame(array_keys(get_object_vars($expected)), array_keys(get_object_vars($actual)), "keys at {$path}");
            foreach (get_object_vars($expected) as $key => $value) {
                $this->assertSameShape($value, $actual->{$key}, "{$path}.{$key}");
            }
        }
    }
}
