<?php

namespace Tests\Feature\EndToEnd;

use App\Models\OutgoingEvent;
use App\Models\Shipment;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsCourierAccounts;
use Tests\Concerns\BuildsShipments;
use Tests\Concerns\SignsApiRequests;
use Tests\TestCase;

/**
 * The POC loop, end to end, with the real QuadX adapter and only HTTP faked: a signed client books a COD parcel,
 * prints its waybill, QuadX reports picked up → in transit → delivered by webhook, and the client receives every
 * change as a signed outgoing webhook, in sequence order. Every queue runs synchronously.
 */
class QuadXLifecycleTest extends TestCase
{
    use BuildsCourierAccounts, BuildsShipments, RefreshDatabase, SignsApiRequests;

    private const HOOK_URL = 'https://client.test/hooks';

    private const WEBHOOK_TOKEN = 'qx-e2e-webhook-token-0123456789abcdefghij';

    private const AWB_HOST = 'quadx-awb.s3.ap-southeast-1.amazonaws.com';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-08 09:00:00', 'Asia/Manila'));
        Storage::fake('local');
        config(['queue.connections.couriers' => ['driver' => 'sync']]);
    }

    public function test_book_label_webhooks_to_delivered(): void
    {
        $pdf = (string) file_get_contents(base_path('tests/Fixtures/pdf/label-a.pdf'));
        Http::fake([
            'api.staging.quadx.xyz/v2/orders' => Http::response($this->quadxFixture('order-created')),
            'api.staging.quadx.xyz/v2/print/awb/*' => Http::response($this->quadxFixture('print-awb')),
            self::AWB_HOST.'/*' => Http::response($pdf, 200, ['Content-Type' => 'application/pdf']),
            'client.test/*' => Http::response('ok', 200),
        ]);

        // 1. The client app, with its outgoing webhook URL.
        $client = $this->makeClient(self::HOOK_URL);

        // 2. A QuadX staging account with keys, the default for `courier: quadx`.
        $this->quadxAccount(attributes: [
            'is_default' => true,
            'default_slot' => 'quadx',
            'webhook_token' => self::WEBHOOK_TOKEN,
            'webhook_token_hash' => hash('sha256', self::WEBHOOK_TOKEN),
        ]);

        // 3. A signed create, COD ₱1,290.00: accepted for booking.
        $created = $this->signed($client, 'POST', '/api/v1/shipments', $this->shipmentBody(), ['Idempotency-Key' => 'order-4211-create'])
            ->assertStatus(202);
        $id = $created->json('data.id');
        $this->assertMatchesRegularExpression('/^shp_[0-9a-z]{26}$/', $id);
        $this->assertSame('1290.00', $created->json('data.cod_amount'));

        // 4. The booking job (synchronous here) booked it with QuadX.
        $shipment = Shipment::query()->where('public_id', $id)->sole();
        $this->assertSame('booked', $shipment->state);
        $this->assertSame('QX-TEST-0001', $shipment->tracking_number);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.staging.quadx.xyz/v2/orders'
            && $r->method() === 'POST'
            && $r['payment_method'] === 'cod'
            && (float) $r['total'] === 1290.0
            && $r['reference_id'] === 'VS000001');

        // 5. The waybill.
        $label = $this->signed($client, 'GET', "/api/v1/shipments/{$id}/label")->assertOk();
        $this->assertStringStartsWith('application/pdf', $label->headers->get('Content-Type'));
        $this->assertSame($pdf, $label->getContent());

        // 6. QuadX reports picked up, in transit and delivered.
        foreach (['webhook-status', 'webhook-in-transit', 'webhook-delivered'] as $fixture) {
            $body = (string) file_get_contents(base_path("tests/Fixtures/quadx/{$fixture}.json"));
            $this->call('POST', '/webhooks/quadx?token='.self::WEBHOOK_TOKEN, [], [], [], ['CONTENT_TYPE' => 'application/json'], $body)
                ->assertOk();
        }

        // 7. Outgoing delivery. One event per shipment goes out per run, the next waiting for the one before it.
        $runs = 0;
        do {
            $this->artisan('ventaship:events-deliver')->assertSuccessful();
            $runs++;
        } while ($runs < 20 && OutgoingEvent::query()->where('status', 'pending')->exists());
        $this->assertSame(6, $runs, 'six events, one per run');

        // 8. Received in sequence order, each signed with the app's webhook secret.
        $hooks = Http::recorded(fn (Request $r) => str_starts_with($r->url(), self::HOOK_URL))->map(fn (array $pair) => $pair[0])->values();
        $envelopes = $hooks->map(function (Request $r) use ($client, $id) {
            $ts = $r->header('X-Timestamp')[0];
            $this->assertTrue(hash_equals('sha256='.hash_hmac('sha256', $ts.'.'.$r->body(), $client['app']->webhook_secret), $r->header('X-Signature')[0]));

            $envelope = json_decode($r->body(), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame($envelope['id'], $r->header('X-Event-Id')[0]);
            $this->assertSame($envelope['type'], $r->header('X-Event-Type')[0]);
            $this->assertSame($client['app']->public_id, $envelope['app']);
            $this->assertSame($id, $envelope['data']['shipment']['id']);

            return $envelope;
        })->all();

        $this->assertSame([1, 2, 3, 4, 5, 6], array_column($envelopes, 'sequence'));
        // The initial courier status is recorded just before `booked`, in the same transaction, so the booked
        // snapshot already carries courier_status and phase.
        $this->assertSame([
            'shipment.status_changed',
            'shipment.booked',
            'shipment.status_changed',
            'shipment.status_changed',
            'shipment.status_changed',
            'shipment.payment_state_changed',
        ], array_column($envelopes, 'type'));
        $this->assertSame(
            ['for_pickup', 'picked_up', 'in_transit', 'delivered'],
            array_values(array_map(fn ($e) => $e['data']['event']['courier_status'], array_filter($envelopes, fn ($e) => $e['type'] === 'shipment.status_changed'))),
        );
        $this->assertSame('booked', $envelopes[1]['data']['shipment']['state']);
        $this->assertSame('QX-TEST-0001', $envelopes[1]['data']['shipment']['tracking_number']);
        $this->assertSame('cod_collected', $envelopes[5]['data']['shipment']['payment_state']);
        $this->assertSame('delivered', $envelopes[5]['data']['shipment']['phase']);

        // 9. The final read.
        $final = $this->signed($client, 'GET', "/api/v1/shipments/{$id}")->assertOk();
        $this->assertSame('delivered', $final->json('data.phase'));
        $this->assertSame('cod_collected', $final->json('data.payment_state'));
        $this->assertSame('delivered', $final->json('data.courier_status'));
        $this->assertSame('booked', $final->json('data.state'));

        $events = $this->signed($client, 'GET', "/api/v1/shipments/{$id}/events")->assertOk();
        $this->assertSame(['for_pickup', 'picked_up', 'in_transit', 'delivered'], array_column($events->json('data'), 'courier_status'));
    }

    /** @param  array{app: \App\Models\ClientApp, key: string, secret: string}  $client */
    private function signed(array $client, string $method, string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->signedJson($method, $uri, $data, $headers + ['X-Timestamp' => $this->uniqueTimestamp()], $client);
    }
}
