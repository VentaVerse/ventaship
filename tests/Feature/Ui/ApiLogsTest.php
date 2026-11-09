<?php

namespace Tests\Feature\Ui;

use App\Models\Admin;
use App\Models\ApiRequest;
use App\Models\ClientApp;
use App\Models\OutgoingEvent;
use App\Models\Shipment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ApiLogsTest extends TestCase
{
    use RefreshDatabase;

    private const MARKER = 'SECRET-PAYLOAD-MARKER';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function admin(): Admin
    {
        return Admin::query()->firstOrCreate(['email' => 'ana@example.com'], ['name' => 'Ana', 'password' => 'correct-horse-battery']);
    }

    private function page(array $query = []): TestResponse
    {
        return $this->actingAs($this->admin())->get(route('developers.index', ['tab' => 'logs'] + $query))->assertOk();
    }

    private function event(ClientApp $app, string $status, array $extra = []): OutgoingEvent
    {
        return OutgoingEvent::query()->create($extra + [
            'client_app_id' => $app->id, 'type' => 'shipment.booked', 'payload' => ['note' => self::MARKER],
            'status' => $status, 'attempts' => 1,
        ]);
    }

    private function apiCall(ClientApp $app, array $extra = []): ApiRequest
    {
        return ApiRequest::query()->create($extra + [
            'client_app_id' => $app->id, 'method' => 'POST', 'path' => '/api/v1/shipments', 'status' => 201,
            'duration_ms' => 42, 'token_prefix' => 'vsk_abcd', 'token_name' => 'Key 1', 'created_at' => now(),
        ]);
    }

    public function test_combined_newest_first_with_results(): void
    {
        $this->travelTo('2026-11-09 15:00:00');
        $app = ClientApp::factory()->create(['name' => 'VentaCart Pro', 'webhook_url' => 'https://example.com/hook']);
        $off = ClientApp::factory()->create(['name' => 'Old Shop', 'status' => 'disabled']);
        $shipment = Shipment::factory()->create(['client_app_id' => $app->id, 'client_reference' => 'ORD-MINE-1']);
        $this->apiCall($app, ['path' => '/api/v1/first', 'created_at' => now()->subMinutes(5)]);
        $this->event($app, 'delivered', ['shipment_id' => $shipment->id, 'created_at' => now()->subMinutes(4)]);
        $this->event($app, 'failed', ['last_error' => 'HTTP 503', 'type' => 'shipment.status_changed', 'created_at' => now()->subMinutes(3)]);
        $this->event($app, 'pending', ['last_error' => 'HTTP 502', 'type' => 'shipment.cancelled', 'created_at' => now()->subMinutes(2)]);
        $this->apiCall($app, ['status' => 422, 'path' => '/api/v1/bad', 'created_at' => now()->subMinute()]);
        $this->apiCall($off, ['status' => 503, 'method' => 'GET', 'path' => '/api/v1/down', 'created_at' => now()]);

        $html = $this->page()
            ->assertSeeInOrder(['Time', 'Type', 'Detail', 'Key', 'Result'])
            ->assertSeeInOrder([
                'GET', '/api/v1/down', 'Old Shop', '503',
                'POST', '/api/v1/bad', '422',
                'shipment.cancelled', 'Retrying',
                'shipment.status_changed', 'Failed · 503',
                'shipment.booked', 'ORD-MINE-1', 'Delivered',
                '/api/v1/first', '201',
            ])
            ->assertSee(route('shipments.show', $shipment), false)
            ->assertDontSee(self::MARKER)
            ->getContent();
        $this->assertSame(2, substr_count($html, '>Resend</button>'));
    }

    public function test_filter_by_type(): void
    {
        $app = ClientApp::factory()->create();
        $this->apiCall($app, ['path' => '/api/v1/only-call']);
        $this->event($app, 'delivered', ['type' => 'only.event']);

        $this->page(['type' => 'api'])->assertSee('/api/v1/only-call')->assertDontSee('only.event');
        $this->page(['type' => 'webhooks'])->assertSee('only.event')->assertDontSee('/api/v1/only-call');
        $html = $this->page(['type' => 'all'])->assertSee('only.event')->assertSee('/api/v1/only-call')->getContent();
        $this->assertMatchesRegularExpression('/aria-current="true"[^>]*>\s*All/', $html);
    }

    public function test_capped_at_one_hundred_newest(): void
    {
        $this->travelTo('2026-11-09 12:00:00');
        $app = ClientApp::factory()->create();
        for ($i = 1; $i <= 60; $i++) {
            $n = str_pad((string) $i, 3, '0', STR_PAD_LEFT);
            $this->apiCall($app, ['path' => '/api/v1/n'.$n, 'created_at' => now()->addSeconds($i * 2)]);
            $this->event($app, 'delivered', ['type' => 'evt.n'.$n, 'created_at' => now()->addSeconds($i * 2 + 1)]);
        }
        $this->page()->assertSee('/api/v1/n060')->assertSee('evt.n011')->assertSee('/api/v1/n011')
            ->assertDontSee('evt.n010')->assertDontSee('/api/v1/n010');
    }

    public function test_no_bodies_or_headers(): void
    {
        $app = ClientApp::factory()->create();
        $this->event($app, 'delivered');
        $this->apiCall($app, ['error' => 'validation', 'idempotency_key' => 'idem-secret-1']);
        $this->page()->assertDontSee(self::MARKER)->assertDontSee('idem-secret-1');
    }

    public function test_resend_success_and_failure(): void
    {
        $app = ClientApp::factory()->create(['webhook_url' => 'https://example.com/hook', 'webhook_secret' => 'whsec_'.str_repeat('a', 40)]);
        $event = $this->event($app, 'failed', ['last_error' => 'HTTP 500']);

        Http::fake(['example.com/*' => Http::sequence()->push('ok', 200)->push('no', 500)]);
        $this->actingAs($this->admin())->post(route('developers.events.resend', $event))
            ->assertRedirect(route('developers.index', ['tab' => 'logs']))->assertSessionHas('status');
        $this->assertSame('delivered', $event->fresh()->status);

        $this->post(route('developers.events.resend', $event))->assertSessionHasErrors('resend');
    }
}
