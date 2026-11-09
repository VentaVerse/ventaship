<?php

namespace Tests\Feature\Webhooks;

use App\Models\ClientApp;
use App\Models\CourierAccount;
use App\Models\OutgoingEvent;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\WebhookReceipt;
use App\Webhooks\Inbound\Jobs\ProcessReceipt;
use App\Webhooks\Inbound\Jobs\RelayReceipt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsCourierAccounts;
use Tests\TestCase;

class InboundQuadXTest extends TestCase
{
    use BuildsCourierAccounts, RefreshDatabase;

    private const TOKEN = 'qx-webhook-token-0123456789abcdefghijklmn';

    private CourierAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->account = $this->quadxAccount(attributes: [
            'webhook_token' => self::TOKEN,
            'webhook_token_hash' => hash('sha256', self::TOKEN),
        ]);
    }

    public function test_valid_token_stores_receipt_acks_and_records_event(): void
    {
        $s = $this->bookedShipment();
        $raw = $this->fixtureBody();

        $this->postRaw('/webhooks/quadx?token='.self::TOKEN, $raw, ['User-Agent' => 'QuadX-Hooks/2', 'Cookie' => 'x=1'])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $receipt = WebhookReceipt::query()->sole();
        $this->assertSame('quadx', $receipt->courier);
        $this->assertSame($this->account->id, (int) $receipt->courier_account_id);
        $this->assertSame('token', $receipt->routed_by);
        $this->assertSame('processed', $receipt->status);
        $this->assertSame($raw, $receipt->body, 'the raw body is stored byte for byte');
        $this->assertSame(['content-type' => 'application/json', 'user-agent' => 'QuadX-Hooks/2'], $receipt->headers);
        $this->assertNotNull($receipt->processed_at);

        $event = ShipmentEvent::query()->sole();
        $this->assertSame($s->id, (int) $event->shipment_id);
        $this->assertSame('webhook', $event->source);
        $this->assertSame('picked_up', $event->courier_status);
        $this->assertSame('Picked up by rider', $event->remarks);
        $this->assertSame($receipt->id, (int) $event->receipt_id);
        $this->assertSame('picked_up', $s->refresh()->courier_status);
        $this->assertSame('shipment.status_changed', OutgoingEvent::query()->sole()->type);
    }

    public function test_route_is_stateless_and_csrf_free(): void
    {
        $this->bookedShipment();

        $response = $this->postRaw('/webhooks/quadx?token='.self::TOKEN, $this->fixtureBody());

        $response->assertOk();
        $this->assertSame([], $response->headers->getCookies(), 'no session or XSRF cookie is set');
    }

    public function test_processing_runs_after_the_response_not_on_the_queue(): void
    {
        Queue::fake();
        $this->bookedShipment();

        $this->postRaw('/webhooks/quadx?token='.self::TOKEN, $this->fixtureBody())->assertOk();

        Queue::assertNothingPushed();
        $this->assertSame('processed', WebhookReceipt::query()->sole()->status);
    }

    public function test_processing_job_times_out_before_its_queue_hands_it_to_another_worker(): void
    {
        config(['queue.default' => 'database']);
        $job = new ProcessReceipt(1);
        $retryAfter = (int) config('queue.connections.'.($job->connection ?? config('queue.default')).'.retry_after');

        $this->assertSame(60, $job->timeout);
        $this->assertLessThan($retryAfter, $job->timeout);
    }

    public function test_bad_token_403_and_not_stored_in_full(): void
    {
        $spx = CourierAccount::factory()->create([
            'courier' => 'spx',
            'webhook_token' => 'spx-account-token-0000000000000000000000',
            'webhook_token_hash' => hash('sha256', 'spx-account-token-0000000000000000000000'),
        ]);

        // No token at all, and a known token that belongs to another courier's account.
        $this->postRaw('/webhooks/quadx', $this->fixtureBody())->assertForbidden();
        $this->postRaw('/webhooks/quadx?token=spx-account-token-0000000000000000000000', $this->fixtureBody())->assertForbidden();

        $rows = WebhookReceipt::query()->orderBy('id')->get();
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertSame('rejected', $row->status);
            $this->assertNull($row->body);
            $this->assertNull($row->headers);
        }
        $this->assertNull($rows[0]->courier_account_id);
        $this->assertSame($spx->id, (int) $rows[1]->courier_account_id);
        $this->assertSame(0, ShipmentEvent::query()->count());
    }

    public function test_unknown_token_is_unrouted_404_and_kept_at_most_16_kb(): void
    {
        $raw = json_encode(['data' => [['tracking_number' => 'QX-1', 'remarks' => str_repeat('a', 20000)]]]);

        $this->postRaw('/webhooks/quadx?token=nobody-has-this-token', $raw)->assertNotFound();

        $receipt = WebhookReceipt::query()->sole();
        $this->assertSame('unrouted', $receipt->status);
        $this->assertNull($receipt->courier_account_id);
        $this->assertSame(16384, strlen($receipt->body));
        $this->assertSame(substr($raw, 0, 16384), $receipt->body);
    }

    public function test_body_over_64_kb_is_stored_truncated_and_still_acked(): void
    {
        $raw = json_encode(['data' => [['tracking_number' => 'QX-TEST-0001', 'remarks' => str_repeat('b', 70000)]]]);

        $this->postRaw('/webhooks/quadx?token='.self::TOKEN, $raw)->assertOk();

        $receipt = WebhookReceipt::query()->sole();
        $this->assertSame(65536, strlen($receipt->body));
        $this->assertSame('failed', $receipt->status);
        $this->assertSame('body truncated', $receipt->error);
    }

    public function test_unmatched_is_200_and_relayed_verbatim_when_relay_url_set(): void
    {
        $relay = 'https://store.example.test/webhooks/courier/quadx?token=core-own-token';
        $this->account->forceFill(['relay_url' => $relay])->save();
        Http::fake(['store.example.test/*' => Http::response(['ok' => true])]);

        // Odd spacing and key order: a re-encoded body would not match.
        $raw = "{ \"data\" : [ {\"tracking_number\":\"QX-NOT-OURS\", \"status\":\"delivered\",\n \"status_updated_at\":\"2026-11-08T11:00:00+08:00\"} ] }";

        $this->postRaw('/webhooks/quadx?token='.self::TOKEN, $raw, ['User-Agent' => 'QuadX-Hooks/2', 'X-Request-Id' => 'req-77', 'X-Other' => 'dropped'])
            ->assertOk();

        $receipt = WebhookReceipt::query()->sole();
        $this->assertSame('unmatched', $receipt->status);
        $this->assertSame(1, $receipt->relay_attempts);

        Http::assertSentCount(1);
        Http::assertSent(function (HttpRequest $r) use ($raw, $relay) {
            return $r->url() === $relay
                && $r->method() === 'POST'
                && $r->body() === $raw
                && $r->header('Content-Type') === ['application/json']
                && $r->header('User-Agent') === ['QuadX-Hooks/2']
                && $r->header('X-Request-Id') === ['req-77']
                && ! $r->hasHeader('X-Other');
        });
    }

    public function test_unmatched_without_relay_url_is_200_and_not_sent_anywhere(): void
    {
        Http::fake();
        $raw = '{"data":[{"tracking_number":"QX-NOT-OURS","status":"delivered"}]}';

        $this->postRaw('/webhooks/quadx?token='.self::TOKEN, $raw)->assertOk();

        $this->assertSame('unmatched', WebhookReceipt::query()->sole()->status);
        Http::assertNothingSent();
    }

    public function test_relay_failure_is_retried_with_backoff(): void
    {
        $job = new RelayReceipt(1);

        $this->assertSame(4, $job->tries, 'the first send plus three retries');
        $this->assertSame([60, 300, 900], $job->backoff());
        $this->assertSame('inbound', $job->queue);
    }

    public function test_relay_refuses_non_http_urls(): void
    {
        $this->account->forceFill(['relay_url' => 'file:///etc/passwd'])->save();
        Http::fake();

        $this->postRaw('/webhooks/quadx?token='.self::TOKEN, '{"data":[{"tracking_number":"QX-NOT-OURS","status":"delivered"}]}')
            ->assertOk();

        Http::assertNothingSent();
        $this->assertSame(0, WebhookReceipt::query()->sole()->relay_attempts);
    }

    public function test_duplicate_webhook_records_once(): void
    {
        $s = $this->bookedShipment();

        $this->postRaw('/webhooks/quadx?token='.self::TOKEN, $this->fixtureBody())->assertOk();
        $this->postRaw('/webhooks/quadx?token='.self::TOKEN, $this->fixtureBody())->assertOk();

        $this->assertSame(2, WebhookReceipt::query()->count());
        $this->assertSame(1, ShipmentEvent::query()->where('shipment_id', $s->id)->count());
        $this->assertSame(1, OutgoingEvent::query()->count());
    }

    public function test_reference_matches_only_a_shipment_still_booking(): void
    {
        $booked = $this->bookedShipment(['tracking_number' => 'QX-OTHER', 'courier_ref' => 'VS000001']);

        $raw = '{"data":[{"reference_id":"VS000001","tracking_number":"QX-UNKNOWN","status":"picked_up","status_updated_at":"2026-11-08T10:00:00+08:00"}]}';
        $this->postRaw('/webhooks/quadx?token='.self::TOKEN, $raw)->assertOk();

        $this->assertSame('unmatched', WebhookReceipt::query()->sole()->status);
        $this->assertSame(0, ShipmentEvent::query()->where('shipment_id', $booked->id)->count());
    }

    public function test_unparseable_body_fails_the_receipt_without_blocking_the_next(): void
    {
        $s = $this->bookedShipment();

        $this->postRaw('/webhooks/quadx?token='.self::TOKEN, '{not json')->assertOk();
        $this->postRaw('/webhooks/quadx?token='.self::TOKEN, $this->fixtureBody())->assertOk();

        [$bad, $good] = WebhookReceipt::query()->orderBy('id')->get()->all();
        $this->assertSame('failed', $bad->status);
        $this->assertStringContainsString('JSON', (string) $bad->error);
        $this->assertNotNull($bad->processed_at);
        $this->assertSame('processed', $good->status);
        $this->assertSame(1, ShipmentEvent::query()->where('shipment_id', $s->id)->count());
    }

    public function test_reprocess_picks_up_receipts_left_unprocessed_for_two_minutes(): void
    {
        $s = $this->bookedShipment();
        $old = $this->receipt(now()->subMinutes(3));
        $recent = $this->receipt(now()->subSeconds(30));

        $this->artisan('ventaship:receipts-reprocess')->assertExitCode(0);

        $this->assertSame('processed', $old->refresh()->status);
        $this->assertSame('received', $recent->refresh()->status);
        $this->assertSame(1, ShipmentEvent::query()->where('shipment_id', $s->id)->count());
    }

    public function test_reprocess_queues_a_receipt_again_at_most_every_five_minutes(): void
    {
        Queue::fake();
        $this->receipt(now()->subMinutes(3));

        $this->artisan('ventaship:receipts-reprocess')->assertExitCode(0);
        $this->artisan('ventaship:receipts-reprocess')->assertExitCode(0);

        Queue::assertPushed(ProcessReceipt::class, 1);
        Queue::assertPushedOn('inbound', ProcessReceipt::class);
    }

    public function test_event_without_a_time_is_dated_by_the_receipt_so_a_reprocess_dedupes(): void
    {
        $s = $this->bookedShipment();
        $raw = '{"data":[{"tracking_number":"QX-TEST-0001","status":"picked_up"}]}';
        $receipt = $this->receipt(now()->subMinutes(10));
        $receipt->forceFill(['body' => $raw])->save();

        (new ProcessReceipt($receipt->id))->handle(app(\App\Shipments\StatusRecorder::class), app(\App\Shipments\BookingOutcomes::class));
        $receipt->forceFill(['status' => 'received', 'processed_at' => null])->save(); // as if processed again
        (new ProcessReceipt($receipt->id))->handle(app(\App\Shipments\StatusRecorder::class), app(\App\Shipments\BookingOutcomes::class));

        $event = ShipmentEvent::query()->where('shipment_id', $s->id)->sole();
        $this->assertSame($receipt->received_at->getTimestamp(), $event->occurred_at->getTimestamp());
    }

    public function test_a_relay_that_cannot_be_queued_is_queued_again_by_reprocess(): void
    {
        $this->account->forceFill(['relay_url' => 'https://store.example.test/hook?token=core'])->save();
        $receipt = $this->receipt(now()->subMinutes(3));
        $receipt->forceFill(['body' => '{"data":[{"tracking_number":"QX-NOT-OURS","status":"delivered"}]}'])->save();

        Bus::shouldReceive('dispatch')->once()->andThrow(new \RuntimeException('queue down'));
        (new ProcessReceipt($receipt->id))->handle(app(\App\Shipments\StatusRecorder::class), app(\App\Shipments\BookingOutcomes::class));
        $this->assertSame('relay_pending', $receipt->refresh()->status);

        Bus::swap(app(\Illuminate\Bus\Dispatcher::class));
        Http::fake(['store.example.test/*' => Http::response('ok')]);
        $this->artisan('ventaship:receipts-reprocess')->assertExitCode(0);

        Http::assertSentCount(1);
        $receipt->refresh();
        $this->assertSame('unmatched', $receipt->status);
        $this->assertSame(1, $receipt->relay_attempts);
    }

    public function test_tracking_on_another_account_of_the_same_courier_is_not_touched(): void
    {
        $other = $this->quadxAccount('qx-other-key', 'qx-other-secret');
        $theirs = $this->bookedShipment(['courier_account_id' => $other->id]);

        $this->postRaw('/webhooks/quadx?token='.self::TOKEN, $this->fixtureBody())->assertOk();

        $this->assertSame('unmatched', WebhookReceipt::query()->sole()->status);
        $this->assertSame(0, ShipmentEvent::query()->where('shipment_id', $theirs->id)->count());
        $this->assertNull($theirs->refresh()->courier_status);
    }

    public function test_webhook_routes_are_rate_limited_per_ip(): void
    {
        $limiter = app(\Illuminate\Cache\RateLimiter::class)->limiter('courier-webhooks');
        $limit = $limiter(\Illuminate\Http\Request::create('/webhooks/quadx', 'POST', server: ['REMOTE_ADDR' => '10.0.0.9']));

        $this->assertSame(300, $limit->maxAttempts);
        $this->assertSame(60, $limit->decaySeconds);
        $this->assertStringContainsString('10.0.0.9', (string) $limit->key);
    }

    public function test_courier_cancellation_of_a_booked_shipment_settles_it(): void
    {
        $s = $this->bookedShipment(['active_reference' => '4211', 'payment_method' => 'cod', 'payment_state' => 'cod_unpaid', 'cod_amount' => 500]);

        $this->postRaw('/webhooks/quadx?token='.self::TOKEN, $this->statusBody('canceled', '2026-11-08T10:00:00+08:00'))->assertOk();

        $s->refresh();
        $this->assertSame('cancelled', $s->state);
        $this->assertSame('cancelled', $s->phase);
        $this->assertSame('cancelled', $s->cancel_state);
        $this->assertNotNull($s->cancelled_at);
        $this->assertNull($s->active_reference);
        $this->assertSame('cod_void', $s->payment_state);
        $this->assertSame(
            ['shipment.status_changed', 'shipment.payment_state_changed', 'shipment.cancelled'],
            OutgoingEvent::query()->orderBy('sequence')->pluck('type')->all(),
        );
        $this->assertSame('cancelled', OutgoingEvent::query()->where('type', 'shipment.cancelled')->sole()->payload['shipment']['state']);

        // A second cancelled report is a new event but not a second cancellation.
        $this->postRaw('/webhooks/quadx?token='.self::TOKEN, $this->statusBody('cancelled', '2026-11-08T10:05:00+08:00'))->assertOk();
        $this->assertSame(2, ShipmentEvent::query()->count());
        $this->assertSame(1, OutgoingEvent::query()->where('type', 'shipment.cancelled')->count());
    }

    public function test_courier_cancellation_after_a_failed_cancel_settles_it_and_clears_that_attention(): void
    {
        $s = $this->bookedShipment([
            'active_reference' => '4211',
            'cancel_state' => 'failed',
            'needs_attention' => true,
            'attention_reason' => 'Cancel outcome unknown — check the courier portal',
        ]);

        $this->postRaw('/webhooks/quadx?token='.self::TOKEN, $this->statusBody('canceled', '2026-11-08T10:00:00+08:00'))->assertOk();

        $s->refresh();
        $this->assertSame('cancelled', $s->state);
        $this->assertSame('cancelled', $s->cancel_state);
        $this->assertNull($s->active_reference);
        $this->assertFalse($s->needs_attention);
        $this->assertNull($s->attention_reason);
        $this->assertSame(1, OutgoingEvent::query()->where('type', 'shipment.cancelled')->count());
    }

    public function test_courier_cancellation_keeps_attention_the_cancel_flow_did_not_raise(): void
    {
        $s = $this->bookedShipment(['needs_attention' => true, 'attention_reason' => 'LBC did not confirm the pickup request']);

        $this->postRaw('/webhooks/quadx?token='.self::TOKEN, $this->statusBody('canceled', '2026-11-08T10:00:00+08:00'))->assertOk();

        $s->refresh();
        $this->assertSame('cancelled', $s->state);
        $this->assertTrue($s->needs_attention);
        $this->assertSame('LBC did not confirm the pickup request', $s->attention_reason);
    }

    private function statusBody(string $status, string $at): string
    {
        return (string) json_encode(['data' => [[
            'reference_id' => 'VS000001',
            'tracking_number' => 'QX-TEST-0001',
            'status' => $status,
            'status_updated_at' => $at,
            'remarks' => null,
        ]]]);
    }

    private function receipt(\DateTimeInterface $at): WebhookReceipt
    {
        return WebhookReceipt::query()->create([
            'courier' => 'quadx',
            'courier_account_id' => $this->account->id,
            'routed_by' => 'token',
            'status' => 'received',
            'headers' => ['content-type' => 'application/json'],
            'body' => $this->fixtureBody(),
            'received_at' => $at,
        ]);
    }

    private function bookedShipment(array $attributes = []): Shipment
    {
        return Shipment::factory()->create($attributes + [
            'client_app_id' => ClientApp::factory(),
            'courier_account_id' => $this->account->id,
            'courier' => 'quadx',
            'state' => 'booked',
            'tracking_number' => 'QX-TEST-0001',
            'courier_ref' => 'VS000001',
            'booked_at' => now()->subHour(),
        ]);
    }

    private function fixtureBody(): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/quadx/webhook-status.json'));
    }

    /** @param  array<string, string>  $headers */
    private function postRaw(string $uri, string $raw, array $headers = []): TestResponse
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->call('POST', $uri, [], [], [], $server, $raw);
    }
}
