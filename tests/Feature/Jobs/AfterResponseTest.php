<?php

namespace Tests\Feature\Jobs;

use App\Couriers\CourierRegistry;
use App\Couriers\FakeCourier;
use App\Couriers\Spx\SpxClient;
use App\Models\Admin;
use App\Models\ClientApp;
use App\Models\CourierAccount;
use App\Models\OutgoingEvent;
use App\Models\Shipment;
use App\Models\WebhookReceipt;
use App\Shipments\StatusRecorder;
use App\Support\AfterResponse;
use App\Webhooks\Inbound\Jobs\ProcessReceipt;
use App\Webhooks\Inbound\Jobs\RelayReceipt;
use App\Webhooks\Outbound\Jobs\DeliverEvent;
use App\Webhooks\Outbound\Outbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\Concerns\BuildsCourierAccounts;
use Tests\Concerns\CallsClientApi;
use Tests\TestCase;

/**
 * Inbound courier webhooks are processed, and outgoing platform webhooks delivered, in the same PHP process once the
 * response is sent (AfterResponse on the app's terminating step, which the test kernel runs after each request).
 * Nothing waits on a queue worker.
 */
class AfterResponseTest extends TestCase
{
    use BuildsCourierAccounts, CallsClientApi, RefreshDatabase;

    private const QX_TOKEN = 'qx-webhook-token-0123456789abcdefghijklmn';

    private const SPX_TOKEN = 'spx-webhook-token-0123456789abcdefghijklm';

    private const SPX_APP_ID = 424242;

    private const SPX_SECRET = 'fake-app-secret-not-real';

    private const HOOK = 'https://store.example.test/hooks/ventaship';

    /** What the database looked like when the response was sent, before any after-response work ran. */
    private array $atResponse = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Registered before any request, so it runs first on terminate: it sees the state the response was sent with.
        $this->app->terminating(function (): void {
            $this->atResponse = [
                'receipts' => WebhookReceipt::query()->pluck('status')->all(),
                'sent' => count(Http::recorded()),
            ];
        });
    }

    public function test_quadx_webhook_is_answered_then_processed_and_delivered_in_the_same_request(): void
    {
        Queue::fake();
        Http::fake(['store.example.test/*' => Http::response('ok')]);
        $account = $this->qxAccount();
        $s = $this->shipment($account, 'quadx', 'QX-TEST-0001', $this->hookApp());

        $this->postQuadx($this->qxBody())->assertOk()->assertJson(['ok' => true]);

        $this->assertSame(['received'], $this->atResponse['receipts'], 'answered before processing');
        $this->assertSame(0, $this->atResponse['sent'], 'nothing delivered before the response');

        $this->assertSame('processed', WebhookReceipt::query()->sole()->status);
        $this->assertSame('picked_up', $s->refresh()->courier_status);
        $event = OutgoingEvent::query()->sole();
        $this->assertSame('delivered', $event->status);
        $this->assertSame(1, $event->attempts);
        Http::assertSentCount(1);
        Http::assertSent(fn (HttpRequest $r) => $r->url() === self::HOOK && $r['type'] === 'shipment.status_changed');
        Queue::assertNothingPushed();
    }

    public function test_spx_webhook_is_answered_then_processed(): void
    {
        Queue::fake();
        $account = $this->spxAccount([
            'webhook_token' => self::SPX_TOKEN,
            'webhook_token_hash' => hash('sha256', self::SPX_TOKEN),
            'external_ref' => (string) self::SPX_APP_ID,
        ]);
        $s = $this->shipment($account, 'spx', 'SPEPH0000000001');

        $this->postSpx('/webhooks/spx/'.self::SPX_TOKEN, $this->spxBody())->assertOk();

        $this->assertSame(['received'], $this->atResponse['receipts']);
        $this->assertSame('processed', WebhookReceipt::query()->sole()->status);
        $this->assertSame('1001', $s->refresh()->courier_status);
        Queue::assertNothingPushed();
    }

    public function test_a_failed_run_leaves_the_receipt_for_reprocess(): void
    {
        $account = $this->qxAccount();
        $this->shipment($account, 'quadx', 'QX-TEST-0001');
        $this->mock(StatusRecorder::class, fn ($m) => $m->shouldReceive('record')->andThrow(new RuntimeException('database went away')));

        $this->postQuadx($this->qxBody())->assertOk();

        $receipt = WebhookReceipt::query()->sole();
        $this->assertSame('received', $receipt->status, 'not settled as failed: the sync queue\'s failed() is not used');
        $this->assertNull($receipt->processed_at);
        $this->assertTrue(Cache::lock("receipt:{$receipt->id}", 1)->get(), 'the processing lock was released');

        Queue::fake();
        $this->travel(3)->minutes();
        $this->artisan('ventaship:receipts-reprocess')->assertExitCode(0);
        Queue::assertPushed(ProcessReceipt::class, fn (ProcessReceipt $j) => $j->receiptId === $receipt->id && ! $j->inline);
    }

    public function test_an_unmatched_receipt_is_relayed_after_the_response(): void
    {
        Queue::fake();
        Http::fake(['relay.example.test/*' => Http::response('ok')]);
        $account = $this->qxAccount(['relay_url' => 'https://relay.example.test/hook?token=theirs']);

        $this->postQuadx('{"data":[{"tracking_number":"QX-NOT-OURS","status":"delivered"}]}')->assertOk();

        $this->assertSame(0, $this->atResponse['sent']);
        $receipt = WebhookReceipt::query()->sole();
        $this->assertSame('unmatched', $receipt->status);
        $this->assertSame(1, $receipt->relay_attempts);
        Http::assertSentCount(1);
        Queue::assertNothingPushed();
        $this->assertSame($account->id, (int) $receipt->courier_account_id);
    }

    public function test_a_failed_relay_is_left_relay_pending_for_reprocess(): void
    {
        Http::fake(['relay.example.test/*' => Http::response('down', 503)]);
        $this->qxAccount(['relay_url' => 'https://relay.example.test/hook?token=theirs']);

        $this->postQuadx('{"data":[{"tracking_number":"QX-NOT-OURS","status":"delivered"}]}')->assertOk();

        $receipt = WebhookReceipt::query()->sole();
        $this->assertSame('relay_pending', $receipt->status);

        Queue::fake();
        $this->artisan('ventaship:receipts-reprocess')->assertExitCode(0);
        Queue::assertPushed(RelayReceipt::class, 1);
    }

    public function test_ui_cancel_delivers_its_event_after_the_response(): void
    {
        Http::fake(['store.example.test/*' => Http::response('ok')]);
        $account = CourierAccount::factory()->create(['courier' => 'quadx', 'status' => 'active']);
        CourierRegistry::fake('quadx', FakeCourier::make('quadx'));
        $s = $this->shipment($account, 'quadx', 'QX-UI-1', $this->hookApp(), ['phase' => 'to_handover']);
        $this->actingAs(Admin::query()->create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'correct-horse-battery']));

        $this->post('/shipments/cancel', ['ids' => [$s->public_id]])->assertRedirect();

        $this->assertSame(0, $this->atResponse['sent']);
        $this->assertSame('cancelled', $s->refresh()->state);
        $this->assertSame(['delivered'], OutgoingEvent::query()->pluck('status')->unique()->values()->all());
        Http::assertSent(fn (HttpRequest $r) => $r['type'] === 'shipment.cancelled');
    }

    public function test_api_refresh_delivers_its_events_after_the_response_in_sequence_order(): void
    {
        $sent = [];
        Http::fake(['store.example.test/*' => function (HttpRequest $r) use (&$sent) {
            $sent[] = $r['sequence'] ?? null;

            return Http::response('ok');
        }]);
        $client = $this->makeClient(self::HOOK);
        $account = CourierAccount::factory()->create(['courier' => 'quadx', 'status' => 'active']);
        CourierRegistry::fake('quadx', FakeCourier::make()->tracks([
            ['status' => 'pending', 'at' => now()->subHours(2)->getTimestamp()],
            ['status' => 'in_transit', 'at' => now()->subHour()->getTimestamp()],
        ]));
        $s = $this->shipment($account, 'quadx', 'QX-API-1', $client['app']);

        $this->apiJson('POST', "/api/v1/shipments/{$s->public_id}/refresh", [], [], $client)->assertOk();

        $this->assertSame(0, $this->atResponse['sent']);
        $events = OutgoingEvent::query()->orderBy('sequence')->get();
        $this->assertGreaterThanOrEqual(2, $events->count());
        $this->assertSame(array_fill(0, $events->count(), 'delivered'), $events->pluck('status')->all());
        $this->assertSame($events->pluck('sequence')->map(fn ($n) => (int) $n)->all(), array_map('intval', $sent), 'sent oldest sequence first');
    }

    public function test_a_later_sequence_waits_while_an_earlier_one_is_still_pending(): void
    {
        Http::fake(['store.example.test/*' => Http::response('ok')]);
        $account = $this->qxAccount();
        $app = $this->hookApp();
        $s = $this->shipment($account, 'quadx', 'QX-TEST-0001', $app);
        $earlier = DB::transaction(fn () => Outbox::record($app, 'shipment.booked', ['event' => null], $s->refresh()));
        $earlier->forceFill(['next_attempt_at' => now()->addMinutes(5)])->save(); // retrying later
        app(AfterResponse::class)->flush(); // nothing ran yet: the earlier one is not due

        $this->postQuadx($this->qxBody())->assertOk();

        Http::assertNothingSent();
        $later = OutgoingEvent::query()->where('type', 'shipment.status_changed')->sole();
        $this->assertSame('pending', $later->status);
        $this->assertSame(0, $later->attempts);
        $this->assertTrue($later->next_attempt_at->isFuture(), 'pushed back behind the earlier sequence');
    }

    public function test_an_app_that_just_failed_to_connect_is_left_to_the_scheduled_retry(): void
    {
        Http::fake();
        $account = $this->qxAccount();
        $app = $this->hookApp();
        $this->shipment($account, 'quadx', 'QX-TEST-0001', $app);
        Cache::put(DeliverEvent::unreachableKey($app->id), true, 60);

        $this->postQuadx($this->qxBody())->assertOk();

        Http::assertNothingSent();
        $event = OutgoingEvent::query()->sole();
        $this->assertSame('pending', $event->status);
        $this->assertSame(0, $event->attempts, 'no attempt spent');
    }

    public function test_nothing_is_delivered_from_a_rolled_back_transaction_and_nothing_inside_one(): void
    {
        Http::fake(['store.example.test/*' => Http::response('ok')]);
        $app = $this->hookApp();
        $queue = app(AfterResponse::class);

        try {
            DB::transaction(function () use ($app): void {
                Outbox::record($app, 'ping', ['message' => 'ping']);
                throw new RuntimeException('rolled back');
            });
        } catch (RuntimeException) {
            // expected
        }
        $this->assertSame(0, $queue->pending());

        DB::transaction(function () use ($app, $queue): void {
            Outbox::record($app, 'ping', ['message' => 'ping']);
            $this->assertSame(0, $queue->pending(), 'nothing queued while the transaction is open');
        });
        $this->assertSame(1, $queue->pending());
        Http::assertNothingSent();

        $this->app->terminate();

        Http::assertSentCount(1);
        $this->assertSame('delivered', OutgoingEvent::query()->sole()->status);
    }

    public function test_after_response_work_runs_once_and_a_failure_does_not_stop_the_rest(): void
    {
        $ran = [];
        AfterResponse::run('one', function () use (&$ran): void {
            $ran[] = 'one';
            AfterResponse::run('nested', function () use (&$ran): void {
                $ran[] = 'nested';
            });
            throw new RuntimeException('boom');
        });
        AfterResponse::run('two', function () use (&$ran): void {
            $ran[] = 'two';
        });

        $this->app->terminate();
        $this->app->terminate();

        $this->assertSame(['one', 'two', 'nested'], $ran);
    }

    private function hookApp(): ClientApp
    {
        return ClientApp::factory()->create(['webhook_url' => self::HOOK]);
    }

    private function qxAccount(array $attributes = []): CourierAccount
    {
        return $this->quadxAccount(attributes: $attributes + [
            'webhook_token' => self::QX_TOKEN,
            'webhook_token_hash' => hash('sha256', self::QX_TOKEN),
        ]);
    }

    private function shipment(CourierAccount $account, string $courier, string $tracking, ?ClientApp $app = null, array $attributes = []): Shipment
    {
        return Shipment::factory()->create($attributes + [
            'client_app_id' => ($app ?? ClientApp::factory()->create())->id,
            'courier_account_id' => $account->id,
            'courier' => $courier,
            'state' => 'booked',
            'tracking_number' => $tracking,
            'courier_ref' => 'VS-'.Str::random(6),
            'booked_at' => now()->subHour(),
        ]);
    }

    private function qxBody(): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/quadx/webhook-status.json'));
    }

    private function spxBody(): string
    {
        return (string) json_encode([
            'status' => 'Pending Pickup',
            'status_code' => '1001',
            'message' => 'Parcel is ready for pickup',
            'tracking_no' => 'SPEPH0000000001',
            'order_id' => 'VS000001',
            'timestamp' => 1762570000,
            'id' => 'd4dcfea1-5286-4921-8fd3-7ded133f8579',
        ], JSON_UNESCAPED_SLASHES);
    }

    private function postQuadx(string $raw): TestResponse
    {
        return $this->call('POST', '/webhooks/quadx?token='.self::QX_TOKEN, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $raw);
    }

    private function postSpx(string $uri, string $raw): TestResponse
    {
        $timestamp = now()->getTimestamp();
        $random = 55;

        return $this->call('POST', $uri, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_APP_ID' => (string) self::SPX_APP_ID,
            'HTTP_CHECK_SIGN' => SpxClient::sign(self::SPX_APP_ID, $timestamp, $random, $raw, self::SPX_SECRET),
            'HTTP_TIMESTAMP' => (string) $timestamp,
            'HTTP_RANDOM_NUM' => (string) $random,
        ], $raw);
    }
}
