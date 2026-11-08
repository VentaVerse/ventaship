<?php

namespace Tests\Feature\Webhooks;

use App\Models\ClientApp;
use App\Models\OutgoingAttempt;
use App\Models\OutgoingEvent;
use App\Models\Shipment;
use App\Webhooks\Outbound\Jobs\DeliverEvent;
use App\Webhooks\Outbound\Outbox;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OutboundDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_test_secret_0123456789';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-08 10:00:00', 'Asia/Manila'));
    }

    private function app(string $url = 'https://client.test/hooks', array $attrs = []): ClientApp
    {
        return ClientApp::factory()->create($attrs + ['webhook_url' => $url, 'webhook_secret' => self::SECRET]);
    }

    private function record(ClientApp $app, string $type = 'shipment.booked', ?Shipment $s = null): OutgoingEvent
    {
        if ($type !== 'ping' && $s === null) {
            $s = Shipment::factory()->create(['client_app_id' => $app->id]);
        }

        $data = $type === 'ping' ? ['message' => 'ping'] : ['event' => null];

        return DB::transaction(fn () => Outbox::record($app, $type, $data, $s?->fresh()));
    }

    public function test_signed_delivery_marks_delivered(): void
    {
        Http::fake(['client.test/*' => Http::response('ok', 200)]);
        $app = $this->app();
        $e = $this->record($app);

        $this->artisan('ventaship:events-deliver')->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(function (Request $r) use ($e, $app) {
            $body = $r->body();
            $ts = $r->header('X-Timestamp')[0];
            $envelope = json_decode($body, true);

            return $r->url() === 'https://client.test/hooks'
                && $r->method() === 'POST'
                && $r->header('Content-Type')[0] === 'application/json'
                && $r->header('User-Agent')[0] === 'Ventaship-Webhooks/1'
                && $r->header('X-Event-Id')[0] === $e->public_id
                && $r->header('X-Event-Type')[0] === 'shipment.booked'
                && $ts === (string) now()->timestamp
                && hash_equals('sha256='.hash_hmac('sha256', $ts.'.'.$body, self::SECRET), $r->header('X-Signature')[0])
                && $envelope['id'] === $e->public_id
                && $envelope['type'] === 'shipment.booked'
                && $envelope['app'] === $app->public_id
                && ! array_key_exists('workspace', $envelope)
                && $envelope['sequence'] === 1
                && $envelope['created_at'] === '2026-11-08T10:00:00+08:00'
                && $envelope['data']['shipment']['id'] === $e->shipment->public_id
                && array_key_exists('event', $envelope['data']);
        });

        $e->refresh();
        $this->assertSame('delivered', $e->status);
        $this->assertSame(1, $e->attempts);
        $this->assertNotNull($e->delivered_at);
        $this->assertNull($e->leased_until);
        $this->assertSame(200, OutgoingAttempt::query()->sole()->status_code);
    }

    public function test_non_2xx_schedules_retry(): void
    {
        $answer = Http::response(str_repeat('x', 5000), 500);
        Http::fake(['client.test/*' => function () use (&$answer) {
            return $answer;
        }]);
        $e = $this->record($this->app());

        $this->artisan('ventaship:events-deliver')->assertSuccessful();

        $e->refresh();
        $this->assertSame('pending', $e->status);
        $this->assertSame(1, $e->attempts);
        $this->assertTrue($e->next_attempt_at->equalTo(now()->addSeconds(60)));
        $this->assertNull($e->leased_until);
        $attempt = OutgoingAttempt::query()->sole();
        $this->assertSame(500, $attempt->status_code);
        $this->assertLessThanOrEqual(2048, strlen($attempt->error));
        $this->assertStringNotContainsString(self::SECRET, (string) $attempt->error.$e->last_error);

        // Not due yet: a second run sends nothing.
        $this->artisan('ventaship:events-deliver')->assertSuccessful();
        Http::assertSentCount(1);

        // A redirect is a failure too, and is not followed.
        $answer = Http::response('', 302, ['Location' => 'https://elsewhere.test/']);
        $this->travel(61)->seconds();
        $this->artisan('ventaship:events-deliver')->assertSuccessful();
        Http::assertSentCount(2);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'elsewhere.test'));
        $this->assertSame(302, OutgoingAttempt::query()->latest('id')->first()->status_code);
        $e->refresh();
        $this->assertSame('pending', $e->status);
        $this->assertSame(2, $e->attempts);
        $this->assertTrue($e->next_attempt_at->equalTo(now()->addSeconds(300)));
    }

    public function test_gives_up_after_72h(): void
    {
        $answer = Http::response('down', 503);
        Http::fake(['client.test/*' => function () use (&$answer) {
            return $answer;
        }]);
        $e = $this->record($this->app());

        $this->travel(72)->hours();
        $e->update(['attempts' => 20, 'next_attempt_at' => now()]);

        $this->artisan('ventaship:events-deliver')->assertSuccessful();

        $e->refresh();
        $this->assertSame('failed', $e->status);
        $this->assertSame(21, $e->attempts);
        $this->assertNull($e->leased_until);

        $this->travel(7)->hours();
        $this->artisan('ventaship:events-deliver')->assertSuccessful();
        Http::assertSentCount(1);

        // The operator resends: a fresh window, delivered on the next run.
        $answer = Http::response('ok', 200);
        $this->artisan('ventaship:events-resend', ['event' => $e->public_id])->assertSuccessful();
        $e->refresh();
        $this->assertSame('pending', $e->status);
        $this->assertTrue($e->window_started_at->equalTo(now()));
        $this->artisan('ventaship:events-deliver')->assertSuccessful();
        $this->assertSame('delivered', $e->refresh()->status);
    }

    public function test_dead_app_does_not_block_other_app(): void
    {
        Http::fake([
            'dead.test/*' => Http::response('boom', 500),
            'client.test/*' => Http::response('ok', 200),
        ]);
        $dead = $this->app('https://dead.test/hooks');
        $alive = $this->app('https://client.test/hooks');
        $deadEvents = [$this->record($dead), $this->record($dead)];
        $aliveEvents = [$this->record($alive), $this->record($alive)];

        $this->artisan('ventaship:events-deliver')->assertSuccessful();

        foreach ($aliveEvents as $e) {
            $this->assertSame('delivered', $e->refresh()->status);
        }
        foreach ($deadEvents as $e) {
            $this->assertSame('pending', $e->refresh()->status);
            $this->assertSame(1, $e->attempts);
        }
    }

    public function test_per_shipment_order_respected(): void
    {
        Http::fake(['client.test/*' => Http::response('ok', 200)]);
        $app = $this->app();
        $s = Shipment::factory()->create(['client_app_id' => $app->id]);
        $first = $this->record($app, 'shipment.booked', $s);
        $second = $this->record($app, 'shipment.status_changed', $s);
        $this->assertSame([1, 2], [$first->sequence, $second->sequence]);

        // Sequence 1 failed once and waits for its retry; sequence 2 is due but must wait behind it.
        $first->update(['attempts' => 1, 'next_attempt_at' => now()->addMinute()]);

        $this->artisan('ventaship:events-deliver')->assertSuccessful();

        Http::assertNothingSent();
        $second->refresh();
        $this->assertSame('pending', $second->status);
        $this->assertSame(0, $second->attempts);
        $this->assertTrue($second->next_attempt_at->equalTo(now()->addSeconds(30)));
        $this->assertSame(0, OutgoingAttempt::query()->count());

        // Once sequence 1 is due, both go out in order.
        $this->travel(61)->seconds();
        $this->artisan('ventaship:events-deliver')->assertSuccessful();

        $sent = Http::recorded()->map(fn ($pair) => $pair[0]->header('X-Event-Id')[0])->all();
        $this->assertSame([$first->public_id, $second->public_id], $sent);
        $this->assertSame('delivered', $second->refresh()->status);
    }

    public function test_settled_earlier_sequences_do_not_block(): void
    {
        Http::fake(['client.test/*' => Http::response('ok', 200)]);
        $app = $this->app();
        $s = Shipment::factory()->create(['client_app_id' => $app->id]);
        $failed = $this->record($app, 'shipment.booked', $s);
        $skipped = $this->record($app, 'shipment.status_changed', $s);
        $third = $this->record($app, 'shipment.status_changed', $s);
        $failed->update(['status' => 'failed']);
        $skipped->update(['status' => 'skipped']);

        $this->artisan('ventaship:events-deliver')->assertSuccessful();

        $this->assertSame('delivered', $third->refresh()->status);
        Http::assertSentCount(1);

        $this->artisan('ventaship:events-resend', ['event' => $skipped->public_id])->assertFailed();
        $this->assertSame('skipped', $skipped->refresh()->status);
    }

    public function test_leased_event_is_not_redelivered_until_the_lease_expires(): void
    {
        Http::fake(['client.test/*' => Http::response('ok', 200)]);
        $e = $this->record($this->app());
        $e->update(['leased_until' => now()->addMinutes(10)]);

        $this->artisan('ventaship:events-deliver')->assertSuccessful();
        (new DeliverEvent($e->id))->handle();
        Http::assertNothingSent();

        $this->travel(10)->minutes();
        $this->artisan('ventaship:events-deliver')->assertSuccessful();
        Http::assertSentCount(1);
        $this->assertSame('delivered', $e->refresh()->status);
    }

    public function test_filtered_type_and_missing_url_are_skipped(): void
    {
        Http::fake(['client.test/*' => Http::response('ok', 200)]);
        $filtered = $this->record($this->app(attrs: ['webhook_events' => ['shipment.status_changed']]));
        $noUrl = $this->record(ClientApp::factory()->create(['webhook_url' => null]));

        $this->artisan('ventaship:events-deliver')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame('skipped', $filtered->refresh()->status);
        $this->assertSame('skipped', $noUrl->refresh()->status);
        $this->assertSame('no webhook url', $noUrl->last_error);
        $this->assertSame(0, OutgoingAttempt::query()->count());
    }

    public function test_connection_error_is_a_failed_attempt(): void
    {
        Http::fake(['client.test/*' => Http::failedConnection()]);
        $e = $this->record($this->app());

        $this->artisan('ventaship:events-deliver')->assertSuccessful();

        $e->refresh();
        $this->assertSame('pending', $e->status);
        $this->assertSame(1, $e->attempts);
        $attempt = OutgoingAttempt::query()->sole();
        $this->assertNull($attempt->status_code);
        $this->assertNotEmpty($attempt->error);
    }

    public function test_deliver_dispatches_at_most_200_due_events_on_the_outgoing_queue(): void
    {
        Queue::fake();
        $app = $this->app();
        $rows = [];
        for ($i = 0; $i < 205; $i++) {
            $rows[] = ['public_id' => 'evt_'.str_pad((string) $i, 26, '0', STR_PAD_LEFT), 'client_app_id' => $app->id, 'type' => 'ping',
                'sequence' => 0, 'payload' => '{"message":"ping"}', 'status' => 'pending', 'attempts' => 0,
                'next_attempt_at' => now(), 'window_started_at' => now(), 'created_at' => now(), 'updated_at' => now()];
        }
        OutgoingEvent::query()->insert($rows);

        $this->artisan('ventaship:events-deliver')->assertSuccessful();

        Queue::assertPushedOn('outgoing', DeliverEvent::class);
        Queue::assertPushed(DeliverEvent::class, 200);
    }

    public function test_ping_command(): void
    {
        Http::fake(['client.test/*' => Http::response('ok', 200)]);
        $app = $this->app();

        $this->artisan('ventaship:app-ping', ['app' => $app->public_id])->assertSuccessful();

        $e = OutgoingEvent::query()->sole();
        $this->assertSame('ping', $e->type);
        $this->assertNull($e->shipment_id);
        $this->assertSame('delivered', $e->status);
        Http::assertSent(function (Request $r) use ($app) {
            $envelope = json_decode($r->body(), true);

            return $envelope['type'] === 'ping' && $envelope['app'] === $app->public_id
                && $envelope['data'] === ['message' => 'ping'] && $envelope['sequence'] === 0;
        });

        $this->artisan('ventaship:app-ping', ['app' => 'app_nope'])->assertFailed();
    }
}
