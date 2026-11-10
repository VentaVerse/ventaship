<?php

namespace Tests\Feature\Webhooks;

use App\Couriers\AccountSettings;
use App\Couriers\Spx\SpxClient;
use App\Http\Controllers\Webhooks\CourierWebhookController;
use App\Models\ClientApp;
use App\Models\CourierAccount;
use App\Models\OutgoingEvent;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\WebhookReceipt;
use App\Shipments\Jobs\CancelAtCourier;
use App\Webhooks\Inbound\AccountRouter;
use App\Webhooks\Inbound\Jobs\ProcessReceipt;
use App\Webhooks\Inbound\ReceiptIngestor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
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

    public function test_bad_signature_is_answered_200_and_stored_rejected_with_headers_and_body(): void
    {
        $this->bookedShipment();
        $raw = $this->payload();
        $bad = str_repeat('0', 64);

        $this->postSigned('/webhooks/spx/'.self::TOKEN, $raw, sign: $bad)->assertOk()->assertExactJson(['ok' => true]);
        $this->postSigned('/webhooks/spx', $raw, sign: $bad)->assertOk();
        // A body changed after signing (status 4001 signed, 1001 sent).
        $signedOther = SpxClient::sign(self::APP_ID, now()->getTimestamp(), 55, $this->payload(['status_code' => '4001']), self::APP_SECRET);
        $this->postSigned('/webhooks/spx/'.self::TOKEN, $raw, sign: $signedOther)->assertOk();

        $rows = WebhookReceipt::query()->orderBy('id')->get();
        $this->assertCount(3, $rows);
        foreach ($rows as $row) {
            $this->assertSame('rejected', $row->status);
            $this->assertSame('signature mismatch', $row->error);
            $this->assertNull($row->external_id);
            $this->assertNull($row->processed_at);
            $this->assertSame($this->account->id, (int) $row->courier_account_id);
            $this->assertSame((string) self::APP_ID, $row->headers['app-id']);
            $this->assertArrayHasKey('check-sign', $row->headers);
            $this->assertArrayHasKey('timestamp', $row->headers);
            $this->assertArrayHasKey('random-num', $row->headers);
            $this->assertStringNotContainsString(self::APP_SECRET, json_encode($row->headers).$row->body);
        }
        $this->assertSame($raw, $rows[0]->body);
        $this->assertSame($bad, $rows[0]->headers['check-sign']);
        $this->assertSame(['token', 'app_id', 'token'], $rows->pluck('routed_by')->all());
        $this->assertSame($raw, $rows[2]->body);
        $this->assertSame(0, ShipmentEvent::query()->count());
        $this->assertSame(0, OutgoingEvent::query()->count());
    }

    public function test_rejected_receipt_is_never_processed_later(): void
    {
        $this->bookedShipment();
        $this->postSigned('/webhooks/spx/'.self::TOKEN, $this->payload(), sign: str_repeat('a', 64))->assertOk();
        WebhookReceipt::query()->update(['received_at' => now()->subHour()]);

        $this->artisan('ventaship:receipts-reprocess')->assertSuccessful();
        app()->call([new ProcessReceipt((int) WebhookReceipt::query()->sole()->id), 'handle']);

        $this->assertSame('rejected', WebhookReceipt::query()->sole()->status);
        $this->assertSame(0, ShipmentEvent::query()->count());
    }

    public function test_rejected_logs_a_warning_with_the_receipt_id(): void
    {
        Log::spy();
        $this->postSigned('/webhooks/spx/'.self::TOKEN, $this->payload(), sign: str_repeat('0', 64))->assertOk();

        $id = (int) WebhookReceipt::query()->sole()->id;
        Log::shouldHaveReceived('warning')->with('SPX webhook rejected', \Mockery::on(fn (array $c) => $c['receipt'] === $id
            && $c['account'] === $this->account->public_id && $c['reason'] === 'signature mismatch'))->once();
    }

    public function test_rejected_body_is_capped_at_64_kb(): void
    {
        $raw = $this->payload(['message' => str_repeat('x', 70000)]);

        $this->postSigned('/webhooks/spx/'.self::TOKEN, $raw, sign: str_repeat('0', 64))->assertOk();

        $this->assertSame(ReceiptIngestor::BODY_CAP, strlen((string) WebhookReceipt::query()->sole()->body));
    }

    public function test_upper_case_check_sign_is_accepted(): void
    {
        $this->bookedShipment();
        $raw = $this->payload();
        $sign = strtoupper(SpxClient::sign(self::APP_ID, now()->getTimestamp(), 55, $raw, self::APP_SECRET));

        $this->postSigned('/webhooks/spx/'.self::TOKEN, $raw, sign: $sign)->assertOk();

        $this->assertSame('processed', WebhookReceipt::query()->sole()->status);
        $this->assertSame(1, ShipmentEvent::query()->count());
    }

    public function test_missing_app_id_header_falls_back_to_the_configured_app_id(): void
    {
        // Another SPX account with its own app pair: the push is checked against each configured app id.
        $other = $this->spxAccount(['external_ref' => '515151']);
        AccountSettings::for($other)->write('spx_sandbox_app_id', '515151');
        AccountSettings::for($other)->writeSecret('spx_sandbox_secret_key', 'other-fake-secret');
        $s = $this->bookedShipment();

        $this->postSigned('/webhooks/spx', $this->payload(), appId: null)->assertOk();
        $this->postSigned('/webhooks/spx/'.self::TOKEN, $this->payload(['id' => 'second-delivery']), appId: null)->assertOk();

        $rows = WebhookReceipt::query()->orderBy('id')->get();
        $this->assertSame(['processed', 'processed'], $rows->pluck('status')->all());
        $this->assertSame(['app_id_fallback', 'token'], $rows->pluck('routed_by')->all());
        $this->assertSame([$this->account->id, $this->account->id], $rows->pluck('courier_account_id')->map(fn ($v) => (int) $v)->all());
        $this->assertArrayNotHasKey('app-id', $rows[0]->headers);
        $this->assertSame(1, ShipmentEvent::query()->where('shipment_id', $s->id)->count());
    }

    public function test_missing_app_id_header_with_a_bad_signature_is_rejected(): void
    {
        $this->bookedShipment();

        $this->postSigned('/webhooks/spx', $this->payload(), sign: str_repeat('0', 64), appId: null)->assertOk();

        $row = WebhookReceipt::query()->sole();
        $this->assertSame('rejected', $row->status);
        $this->assertSame('app_id_fallback', $row->routed_by);
        $this->assertSame($this->account->id, (int) $row->courier_account_id);
        $this->assertSame(0, ShipmentEvent::query()->count());
    }

    public function test_stale_or_future_timestamp_is_rejected(): void
    {
        $this->bookedShipment();
        $limit = AccountRouter::FRESH_SECONDS;

        $this->postSigned('/webhooks/spx/'.self::TOKEN, $this->payload(['id' => 'a']), timestamp: now()->getTimestamp() - $limit - 1)->assertOk();
        $this->postSigned('/webhooks/spx/'.self::TOKEN, $this->payload(['id' => 'b']), timestamp: now()->getTimestamp() + $limit + 1)->assertOk();
        $this->postSigned('/webhooks/spx', $this->payload(['id' => 'c']), timestamp: 1762570000)->assertOk();

        $rows = WebhookReceipt::query()->orderBy('id')->get();
        $this->assertSame(['rejected', 'rejected', 'rejected'], $rows->pluck('status')->all());
        $this->assertStringStartsWith('timestamp ', (string) $rows[0]->error);
        $this->assertSame(0, ShipmentEvent::query()->count());

        // Just inside the window either way.
        $this->postSigned('/webhooks/spx/'.self::TOKEN, $this->payload(['id' => 'd']), timestamp: now()->getTimestamp() - $limit)->assertOk();
        $this->postSigned('/webhooks/spx/'.self::TOKEN, $this->payload(['id' => 'e', 'status_code' => '2001', 'status' => 'In Transit']), timestamp: now()->getTimestamp() + $limit)->assertOk();
        $this->assertSame(2, WebhookReceipt::query()->where('status', 'processed')->count());
    }

    public function test_unknown_token_or_app_id_is_unrouted_200(): void
    {
        $this->postSigned('/webhooks/spx/no-such-token', $this->payload())->assertOk();
        $this->postSigned('/webhooks/spx', $this->payload(), appId: 777)->assertOk();

        $rows = WebhookReceipt::query()->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame(['unrouted', 'unrouted'], $rows->pluck('status')->all());
        $this->assertSame(['unknown token', 'unknown app id'], $rows->pluck('error')->all());
        $this->assertNull($rows[0]->courier_account_id);
        $this->assertNotNull($rows[0]->body);
        $this->assertSame(0, ShipmentEvent::query()->count());
    }

    public function test_a_body_that_is_not_json_is_400_and_stored_rejected(): void
    {
        $this->postSigned('/webhooks/spx/'.self::TOKEN, 'status=1001', sign: str_repeat('0', 64))->assertStatus(400)->assertExactJson(['ok' => false]);
        $this->postSigned('/webhooks/spx', 'not json', appId: 777)->assertStatus(400);

        $rows = WebhookReceipt::query()->orderBy('id')->get();
        $this->assertSame(['rejected', 'rejected'], $rows->pluck('status')->all());
        $this->assertSame(['body is not JSON', 'body is not JSON'], $rows->pluck('error')->all());
        $this->assertSame('status=1001', $rows[0]->body);
        $this->assertSame($this->account->id, (int) $rows[0]->courier_account_id);
        $this->assertNull($rows[1]->courier_account_id);
    }

    public function test_unverified_requests_past_the_per_ip_budget_are_answered_but_not_stored(): void
    {
        $s = $this->bookedShipment();
        for ($i = 0; $i < CourierWebhookController::UNVERIFIED_PER_MINUTE; $i++) {
            RateLimiter::hit('spx-webhook-unverified:ip:127.0.0.1', 60);
        }

        $this->postSigned('/webhooks/spx/'.self::TOKEN, $this->payload(), sign: str_repeat('0', 64))->assertOk();
        $this->postSigned('/webhooks/spx', $this->payload(), appId: 777)->assertOk();
        $this->assertSame(0, WebhookReceipt::query()->count());

        // A verified push is never held back by it.
        $this->postSigned('/webhooks/spx/'.self::TOKEN, $this->payload())->assertOk();
        $this->assertSame('processed', WebhookReceipt::query()->sole()->status);
        $this->assertSame(1, ShipmentEvent::query()->where('shipment_id', $s->id)->count());
    }

    public function test_spx_is_not_on_the_300_per_minute_courier_webhook_throttle(): void
    {
        $this->bookedShipment();
        $key = md5('courier-webhooks'.'courier-webhooks:ip:127.0.0.1');
        for ($i = 0; $i < 400; $i++) {
            RateLimiter::hit($key, 60);
        }
        // The QuadX route is throttled for this IP now...
        $this->postJson('/webhooks/quadx?token=x')->assertStatus(429);

        // ...SPX is not.
        $this->postSigned('/webhooks/spx/'.self::TOKEN, $this->payload())->assertOk();
        $this->postSigned('/webhooks/spx', $this->payload(['id' => 'other']))->assertOk();
        $this->assertSame(2, WebhookReceipt::query()->where('status', 'processed')->count());

        $limit = RateLimiter::limiter('spx-webhooks')(request());
        $this->assertGreaterThanOrEqual(3000, $limit->maxAttempts);
        $this->assertSame(60, $limit->decaySeconds);
    }

    public function test_rejected_spx_receipts_are_pruned_after_7_days(): void
    {
        $this->postSigned('/webhooks/spx/'.self::TOKEN, $this->payload(), sign: str_repeat('0', 64))->assertOk();
        $this->postSigned('/webhooks/spx/'.self::TOKEN, $this->payload(['id' => 'fresh']), sign: str_repeat('0', 64))->assertOk();
        WebhookReceipt::query()->orderBy('id')->first()->forceFill(['received_at' => now()->subDays(8)])->save();

        $this->artisan('ventaship:logs-prune')->assertSuccessful();

        $this->assertSame(1, WebhookReceipt::query()->count());
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
        $timestamp = now()->getTimestamp();
        $sign = SpxClient::sign(self::APP_ID, $timestamp, 55, $raw, self::APP_SECRET);

        $this->postSigned('/webhooks/spx/'.self::TOKEN, $raw)->assertOk();

        $this->assertSame('unmatched', WebhookReceipt::query()->sole()->status);
        Http::assertSent(fn (HttpRequest $r) => $r->url() === $relay
            && $r->body() === $raw
            && $r->header('check-sign') === [$sign]
            && $r->header('app-id') === [(string) self::APP_ID]
            && $r->header('timestamp') === [(string) $timestamp]
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

    /** $appId null: no app-id header (the signature is still made with APP_ID). $timestamp defaults to now. */
    private function postSigned(string $uri, string $raw, ?string $sign = null, ?string $signedBody = null, ?int $appId = self::APP_ID, ?int $timestamp = null): TestResponse
    {
        $timestamp ??= now()->getTimestamp();
        $random = 55;
        $sign ??= SpxClient::sign($appId ?? self::APP_ID, $timestamp, $random, $signedBody ?? $raw, self::APP_SECRET);
        $body = $signedBody !== null ? $raw.'x' : $raw;

        return $this->call('POST', $uri, [], [], [], array_filter([
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_APP_ID' => $appId === null ? null : (string) $appId,
            'HTTP_CHECK_SIGN' => $sign,
            'HTTP_TIMESTAMP' => (string) $timestamp,
            'HTTP_RANDOM_NUM' => (string) $random,
        ], fn ($v) => $v !== null), $body);
    }
}
