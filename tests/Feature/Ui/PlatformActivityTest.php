<?php

namespace Tests\Feature\Ui;

use App\Models\Admin;
use App\Models\ApiRequest;
use App\Models\ClientApp;
use App\Models\OutgoingEvent;
use App\Models\Shipment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PlatformActivityTest extends TestCase
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
        return Admin::query()->create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'correct-horse-battery']);
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

    public function test_both_lists_render_scoped_to_the_app(): void
    {
        $app = ClientApp::factory()->create(['webhook_url' => 'https://example.com/hook']);
        $other = ClientApp::factory()->create();
        $shipment = Shipment::factory()->create(['client_app_id' => $app->id, 'client_reference' => 'ORD-MINE-1']);
        $this->event($app, 'delivered', ['shipment_id' => $shipment->id]);
        $this->event($app, 'failed', ['last_error' => 'HTTP 500']);
        $this->event($app, 'pending', ['last_error' => 'HTTP 502']);
        $this->event($other, 'delivered', ['type' => 'other.app.event']);
        $this->apiCall($app, ['path' => '/api/v1/mine']);
        $this->apiCall($app, ['status' => 422, 'path' => '/api/v1/bad']);
        $this->apiCall($app, ['status' => 503, 'path' => '/api/v1/down']);
        $this->apiCall($other, ['path' => '/api/v1/theirs']);

        $res = $this->actingAs($this->admin())->get(route('apps.show', $app))->assertOk();
        $res->assertSee('shipment.booked')->assertSee('ORD-MINE-1')->assertSee(route('shipments.show', $shipment), false)
            ->assertSee('Delivered')->assertSee('Retrying')->assertSee('Failed')->assertSee('HTTP 500')
            ->assertSee('/api/v1/mine')->assertSee('/api/v1/bad')->assertSee('/api/v1/down')->assertSee('vsk_abcd')->assertSee('42 ms')
            ->assertDontSee('other.app.event')->assertDontSee('/api/v1/theirs')->assertDontSee(self::MARKER);
    }

    public function test_empty_states(): void
    {
        $app = ClientApp::factory()->create();
        $this->actingAs($this->admin())->get(route('apps.show', $app))->assertSee('No deliveries')->assertSee('No API calls');
    }

    public function test_lists_are_capped_at_fifty_newest(): void
    {
        $app = ClientApp::factory()->create();
        for ($i = 1; $i <= 55; $i++) {
            $this->apiCall($app, ['path' => '/api/v1/n'.str_pad((string) $i, 3, '0', STR_PAD_LEFT)]);
            $this->event($app, 'delivered', ['type' => 'evt.n'.str_pad((string) $i, 3, '0', STR_PAD_LEFT)]);
        }
        $res = $this->actingAs($this->admin())->get(route('apps.show', $app));
        $res->assertSee('/api/v1/n055')->assertSee('/api/v1/n006')->assertDontSee('/api/v1/n005')
            ->assertSee('evt.n055')->assertSee('evt.n006')->assertDontSee('evt.n005');
    }

    public function test_no_bodies_or_headers_in_html(): void
    {
        $app = ClientApp::factory()->create();
        $this->event($app, 'delivered');
        $this->apiCall($app, ['error' => 'validation', 'idempotency_key' => 'idem-secret-1']);
        $this->actingAs($this->admin())->get(route('apps.show', $app))->assertDontSee(self::MARKER)->assertDontSee('idem-secret-1');
    }

    public function test_resend_success_and_failure(): void
    {
        $app = ClientApp::factory()->create(['webhook_url' => 'https://example.com/hook', 'webhook_secret' => 'whsec_'.str_repeat('a', 40)]);
        $event = $this->event($app, 'failed', ['last_error' => 'HTTP 500']);
        $admin = $this->admin();

        Http::fake(['example.com/*' => Http::sequence()->push('ok', 200)->push('no', 500)]);
        $this->actingAs($admin)->post(route('apps.events.resend', [$app, $event]))
            ->assertRedirect(route('apps.show', $app).'#activity')->assertSessionHas('status');
        $this->assertSame('delivered', $event->fresh()->status);

        $this->actingAs($admin)->post(route('apps.events.resend', [$app, $event]))->assertSessionHasErrors('resend');
    }

    public function test_resend_of_another_apps_event_is_404(): void
    {
        $app = ClientApp::factory()->create();
        $other = ClientApp::factory()->create();
        $event = $this->event($other, 'failed');
        $this->actingAs($this->admin())->post(route('apps.events.resend', [$app, $event]))->assertNotFound();
        $this->assertSame('failed', $event->fresh()->status);
    }

    public function test_guests_are_redirected(): void
    {
        $app = ClientApp::factory()->create();
        $event = $this->event($app, 'failed');
        $this->post(route('apps.events.resend', [$app, $event]))->assertRedirect(route('login'));
    }
}
