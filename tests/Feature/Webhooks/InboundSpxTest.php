<?php

namespace Tests\Feature\Webhooks;

use App\Couriers\Spx\SpxClient;
use App\Models\ClientApp;
use App\Models\CourierAccount;
use App\Models\OutgoingEvent;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\WebhookReceipt;
use App\Shipments\Jobs\CancelAtCourier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsCourierAccounts;
use Tests\TestCase;

/**
 * Signatures are made with SpxClient::sign(), proven byte-exact against SPX's published test vector in
 * tests/Unit/Couriers/SpxSignerTest.php. The app pair is the obviously fake one spxAccount() stores.
 */
class InboundSpxTest extends TestCase
{
    use BuildsCourierAccounts, RefreshDatabase;

    private const TOKEN = 'spx-webhook-token-0123456789abcdefghijklm';

    private const APP_ID = 424242;

    private const APP_SECRET = 'fake-app-secret-not-real';

    private CourierAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->account = $this->spxAccount([
            'webhook_token' => self::TOKEN,
            'webhook_token_hash' => hash('sha256', self::TOKEN),
            'external_ref' => (string) self::APP_ID,
        ]);
    }

    public function test_path_token_and_signature(): void
    {
        $s = $this->bookedShipment();
        $raw = $this->payload();

        $this->postSigned('/webhooks/spx/'.self::TOKEN, $raw)->assertOk();

        $receipt = WebhookReceipt::query()->sole();
        $this->assertSame('token', $receipt->routed_by);
        $this->assertSame('processed', $receipt->status);
        $this->assertSame('d4dcfea1-5286-4921-8fd3-7ded133f8579', $receipt->external_id);
        $this->assertSame($raw, $receipt->body);
        $this->assertSame((string) self::APP_ID, $receipt->headers['app-id']);
        $this->assertArrayHasKey('check-sign', $receipt->headers);

        $event = ShipmentEvent::query()->sole();
        $this->assertSame($s->id, (int) $event->shipment_id);
        $this->assertSame('webhook', $event->source);
        $this->assertSame('1001', $event->courier_status);
        $this->assertSame('shipment.status_changed', OutgoingEvent::query()->sole()->type);
    }

    public function test_app_id_fallback(): void
    {
        $s = $this->bookedShipment();

        $this->postSigned('/webhooks/spx', $this->payload())->assertOk();

        $receipt = WebhookReceipt::query()->sole();
        $this->assertSame('app_id', $receipt->routed_by);
        $this->assertSame($this->account->id, (int) $receipt->courier_account_id);
        $this->assertSame(1, ShipmentEvent::query()->where('shipment_id', $s->id)->count());
    }

    public function test_app_id_shared_by_two_accounts_routes_by_tracking_number(): void
    {
        $other = $this->spxAccount(['external_ref' => (string) self::APP_ID]);
        $s = $this->bookedShipment(['courier_account_id' => $other->id]);

        $this->postSigned('/webhooks/spx', $this->payload())->assertOk();

        $receipt = WebhookReceipt::query()->sole();
        $this->assertSame('tracking', $receipt->routed_by);
        $this->assertSame($other->id, (int) $receipt->courier_account_id);
        $this->assertSame(1, ShipmentEvent::query()->where('shipment_id', $s->id)->count());
    }

    public function test_bad_signature_401(): void
    {
        $this->bookedShipment();
        $raw = $this->payload();

        $this->postSigned('/webhooks/spx/'.self::TOKEN, $raw, sign: str_repeat('0', 64))->assertUnauthorized();
        $this->postSigned('/webhooks/spx', $raw, sign: str_repeat('0', 64))->assertUnauthorized();
        // A body changed after signing.
        $this->postSigned('/webhooks/spx/'.self::TOKEN, $raw, signedBody: $raw.' ')->assertUnauthorized();

        $rows = WebhookReceipt::query()->get();
        $this->assertCount(3, $rows);
        foreach ($rows as $row) {
            $this->assertSame('rejected', $row->status);
            $this->assertNull($row->body);
            $this->assertNull($row->headers);
            $this->assertNull($row->external_id);
            $this->assertSame($this->account->id, (int) $row->courier_account_id);
        }
        $this->assertSame(0, ShipmentEvent::query()->count());
    }

    public function test_unknown_token_or_app_id_is_unrouted_401(): void
    {
        $this->postSigned('/webhooks/spx/no-such-token', $this->payload())->assertUnauthorized();
        $this->postSigned('/webhooks/spx', $this->payload(), appId: 777)->assertUnauthorized();

        $rows = WebhookReceipt::query()->get();
        $this->assertCount(2, $rows);
        $this->assertSame(['unrouted', 'unrouted'], $rows->pluck('status')->all());
        $this->assertNull($rows[0]->courier_account_id);
        $this->assertNotNull($rows[0]->body);
    }

    public function test_duplicate_id_is_dropped_at_receipt_level(): void
    {
        $this->bookedShipment();

        $this->postSigned('/webhooks/spx/'.self::TOKEN, $this->payload())->assertOk();
        $this->postSigned('/webhooks/spx/'.self::TOKEN, $this->payload())->assertOk();

        $this->assertSame(1, WebhookReceipt::query()->count());
        $this->assertSame(1, ShipmentEvent::query()->count());
    }

    public function test_unmatched_relay_forwards_the_signature_headers_verbatim(): void
    {
        $relay = 'https://store.example.test/webhooks/courier/spx-key';
        $this->account->forceFill(['relay_url' => $relay])->save();
        Http::fake(['store.example.test/*' => Http::response('', 204)]);
        $raw = $this->payload(['tracking_no' => 'SPX-NOT-OURS', 'order_id' => 'core-4211']);
        $sign = SpxClient::sign(self::APP_ID, 1762570000, 55, $raw, self::APP_SECRET);

        $this->postSigned('/webhooks/spx/'.self::TOKEN, $raw)->assertOk();

        $this->assertSame('unmatched', WebhookReceipt::query()->sole()->status);
        Http::assertSent(fn (HttpRequest $r) => $r->url() === $relay
            && $r->body() === $raw
            && $r->header('check-sign') === [$sign]
            && $r->header('app-id') === [(string) self::APP_ID]
            && $r->header('timestamp') === ['1762570000']
            && $r->header('random-num') === ['55']);
    }

    public function test_creating_shipment_matched_by_reference_is_booked_and_requested_cancel_dispatched(): void
    {
        Queue::fake([CancelAtCourier::class]);
        $s = $this->bookedShipment([
            'state' => 'booking', 'courier_status' => 'creating', 'tracking_number' => null,
            'booked_at' => null, 'cancel_state' => 'requested', 'courier_ref' => 'VS000042',
        ]);

        $this->postSigned('/webhooks/spx/'.self::TOKEN, $this->payload(['order_id' => 'VS000042']))->assertOk();

        $s->refresh();
        $this->assertSame('booked', $s->state);
        $this->assertSame('SPEPH0000000001', $s->tracking_number);
        $this->assertSame('1001', $s->courier_status);
        $this->assertNotNull($s->booked_at);
        $this->assertSame(['shipment.booked', 'shipment.status_changed'], OutgoingEvent::query()->orderBy('id')->pluck('type')->all());
        Queue::assertPushed(CancelAtCourier::class, fn ($job) => $job->shipmentId === $s->id);
    }

    private function bookedShipment(array $attributes = []): Shipment
    {
        return Shipment::factory()->create($attributes + [
            'client_app_id' => ClientApp::factory(),
            'courier_account_id' => $this->account->id,
            'courier' => 'spx',
            'state' => 'booked',
            'tracking_number' => 'SPEPH0000000001',
            'courier_ref' => 'VS000001',
            'booked_at' => now()->subHour(),
        ]);
    }

    /** The documented SPX tracking callback (api.md §4.1), compact as SPX sends it. */
    private function payload(array $overrides = []): string
    {
        return json_encode($overrides + [
            'status' => 'Pending Pickup',
            'status_code' => '1001',
            'message' => 'Parcel is ready for pickup',
            'tracking_no' => 'SPEPH0000000001',
            'tracking_link' => 'https://test.spx.co.th/track?SPEPH0000000001',
            'order_id' => 'VS000001',
            'timestamp' => 1762570000,
            'id' => 'd4dcfea1-5286-4921-8fd3-7ded133f8579',
        ], JSON_UNESCAPED_SLASHES);
    }

    private function postSigned(string $uri, string $raw, ?string $sign = null, ?string $signedBody = null, int $appId = self::APP_ID): TestResponse
    {
        $timestamp = 1762570000;
        $random = 55;
        $sign ??= SpxClient::sign($appId, $timestamp, $random, $signedBody ?? $raw, self::APP_SECRET);
        $body = $signedBody !== null ? $raw.'x' : $raw;

        return $this->call('POST', $uri, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_APP_ID' => (string) $appId,
            'HTTP_CHECK_SIGN' => $sign,
            'HTTP_TIMESTAMP' => (string) $timestamp,
            'HTTP_RANDOM_NUM' => (string) $random,
        ], $body);
    }
}
