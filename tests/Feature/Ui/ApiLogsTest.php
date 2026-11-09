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

    public function test_pages_of_twenty_five_newest_first_keeping_the_filter(): void
    {
        $this->travelTo('2026-11-09 12:00:00');
        $app = ClientApp::factory()->create();
        for ($i = 1; $i <= 60; $i++) {
            $n = str_pad((string) $i, 3, '0', STR_PAD_LEFT);
            $this->apiCall($app, ['path' => '/api/v1/n'.$n, 'created_at' => now()->addSeconds($i * 2)]);
            $this->event($app, 'delivered', ['type' => 'evt.n'.$n, 'created_at' => now()->addSeconds($i * 2 + 1)]);
        }

        // 120 rows: page 1 is evt.n060 down to evt.n048; its call (one second older) opens page 2.
        $first = $this->page()->assertSeeInOrder(['evt.n060', '/api/v1/n060', 'evt.n059', 'evt.n048'])
            ->assertDontSee('/api/v1/n048')->assertSee('1 to 25 of 120')->getContent();
        $this->assertSame(25, substr_count($first, 'data-label="Time"'));
        $this->assertStringContainsString('href="'.e(route('developers.index', ['tab' => 'logs', 'page' => 2])).'"', $first);
        $this->page(['page' => 2])->assertSeeInOrder(['/api/v1/n048', 'evt.n047'])->assertDontSee('evt.n048')->assertSee('26 to 50 of 120');
        $last = $this->page(['page' => 5])->assertSee('101 to 120 of 120')->assertSee('evt.n001')->assertSee('/api/v1/n001')->getContent();
        $this->assertSame(20, substr_count($last, 'data-label="Time"'));
        // Past the end (or nonsense) lands on the last page or the first.
        $this->page(['page' => 99])->assertSee('101 to 120 of 120');
        $this->page(['page' => 'x'])->assertSee('1 to 25 of 120');

        $calls = $this->page(['type' => 'api'])->assertSee('1 to 25 of 60')->assertDontSee('evt.n060')->getContent();
        $this->assertStringContainsString('href="'.e(route('developers.index', ['tab' => 'logs', 'type' => 'api', 'page' => 2])).'"', $calls);
        $this->page(['type' => 'api', 'page' => 3])->assertSee('51 to 60 of 60')->assertSee('/api/v1/n001')->assertDontSee('evt.n001');
        $this->page(['type' => 'webhooks', 'page' => 3])->assertSee('51 to 60 of 60')->assertSee('evt.n001')->assertDontSee('/api/v1/n001');
    }

    public function test_one_page_has_no_pager(): void
    {
        $app = ClientApp::factory()->create();
        $this->apiCall($app);
        $this->page()->assertSee('/api/v1/shipments')->assertDontSee('x-pager', false);
    }

    public function test_failed_filter_count_and_why(): void
    {
        $this->travelTo('2026-11-09 15:00:00');
        $app = ClientApp::factory()->create();
        $this->apiCall($app, ['path' => '/api/v1/fine', 'created_at' => now()->subMinutes(9)]);
        $this->apiCall($app, ['path' => '/api/v1/limited', 'status' => 429, 'error' => 'rate_limited', 'created_at' => now()->subMinutes(8)]);
        $this->apiCall($app, ['path' => '/api/v1/invalid', 'status' => 422, 'created_at' => now()->subMinutes(7)]);
        $this->apiCall($app, ['path' => '/api/v1/ok-with-code', 'status' => 200, 'error' => 'not_shown_when_ok', 'created_at' => now()->subMinutes(6)]);
        $this->event($app, 'failed', ['type' => 'evt.refused', 'created_at' => now()->subMinutes(5),
            'last_error' => 'Illuminate\\Http\\Client\\ConnectionException: cURL error 7: Failed to connect to 192.168.0.224 port 8032: Connection refused']);
        $this->event($app, 'failed', ['type' => 'evt.answered', 'created_at' => now()->subMinutes(4), 'last_error' => 'HTTP 503: BODY-OF-THE-RECEIVER']);
        $this->event($app, 'failed', ['type' => 'evt.markup', 'created_at' => now()->subMinutes(3), 'last_error' => 'RuntimeException: <script>alert(1)</script>']);
        $this->event($app, 'pending', ['type' => 'evt.retrying', 'created_at' => now()->subMinutes(2), 'last_error' => 'RuntimeException: still trying']);
        $this->event($app, 'delivered', ['type' => 'evt.fine', 'created_at' => now()->subMinute()]);

        $all = $this->page()->getContent();
        $this->assertMatchesRegularExpression('#Failed<span class="api-seg__count x-num">5</span>#', $all);
        $this->assertStringContainsString('<p class="api-why">Rate limited</p>', $all);
        $this->assertStringContainsString('<p class="api-why">cURL error 7: Failed to connect to 192.168.0.224 port 8032: Connection refused</p>', $all);
        $this->assertStringContainsString('<p class="api-why">&lt;script&gt;alert(1)&lt;/script&gt;</p>', $all);
        $this->assertStringNotContainsString('<script>alert(1)', $all);
        $this->assertStringNotContainsString('BODY-OF-THE-RECEIVER', $all);
        $this->assertStringNotContainsString('ConnectionException', $all);
        $this->assertStringNotContainsString('still trying', $all);
        $this->assertStringNotContainsString('Not shown when ok', $all);
        $this->assertSame(3, substr_count($all, 'class="api-why"'));

        $failed = $this->page(['type' => 'failed'])
            ->assertSeeInOrder(['evt.markup', 'evt.answered', 'evt.refused', '/api/v1/invalid', '/api/v1/limited'])
            ->assertDontSee('/api/v1/fine')->assertDontSee('/api/v1/ok-with-code')->assertDontSee('evt.retrying')->assertDontSee('evt.fine')
            ->getContent();
        $this->assertMatchesRegularExpression('/aria-current="true"[^>]*>\s*Failed/', $failed);
        $this->assertSame(5, substr_count($failed, 'data-label="Time"'));
    }

    public function test_failed_filter_pages_keep_the_filter(): void
    {
        $this->travelTo('2026-11-09 12:00:00');
        $app = ClientApp::factory()->create();
        for ($i = 1; $i <= 30; $i++) {
            $this->apiCall($app, ['status' => 500, 'path' => '/api/v1/bad'.$i, 'created_at' => now()->addSeconds($i)]);
            $this->apiCall($app, ['status' => 200, 'created_at' => now()->addSeconds($i)]);
        }

        $html = $this->page(['type' => 'failed'])->assertSee('1 to 25 of 30')->getContent();
        $this->assertMatchesRegularExpression('#Failed<span class="api-seg__count x-num">30</span>#', $html);
        $this->assertStringContainsString('href="'.e(route('developers.index', ['tab' => 'logs', 'type' => 'failed', 'page' => 2])).'"', $html);
        $this->page(['type' => 'failed', 'page' => 2])->assertSee('26 to 30 of 30')->assertSee('/api/v1/bad1<', false)->assertDontSee('/api/v1/shipments');
    }

    public function test_a_delivery_links_the_shipment_by_its_reference(): void
    {
        $app = ClientApp::factory()->create();
        $booked = Shipment::factory()->create(['client_app_id' => $app->id, 'client_reference' => '102', 'courier_ref' => 'VS000006']);
        $draft = Shipment::factory()->create(['client_app_id' => $app->id, 'client_reference' => 'ORD-77', 'courier_ref' => null]);
        $this->event($app, 'delivered', ['shipment_id' => $booked->id]);
        $this->event($app, 'delivered', ['shipment_id' => $draft->id]);

        $html = $this->page()->getContent();
        $this->assertStringContainsString('<a class="api-mono" href="'.route('shipments.show', $booked).'">VS000006</a>', $html);
        $this->assertStringContainsString('<a class="api-mono" href="'.route('shipments.show', $draft).'">ORD-77</a>', $html);
        $this->assertStringNotContainsString('>102<', $html);
    }

    public function test_the_key_column_shows_only_with_several_apps(): void
    {
        $app = ClientApp::factory()->create(['name' => 'Only Shop']);
        $this->apiCall($app);
        $one = $this->page()->getContent();
        $this->assertStringNotContainsString('data-label="Key"', $one);
        $this->assertStringNotContainsString('>Key</th>', $one);

        ClientApp::factory()->create(['name' => 'Second Shop']);
        $two = $this->page()->getContent();
        $this->assertStringContainsString('<td class="api-sub" data-label="Key">Only Shop</td>', $two);
        $this->assertStringContainsString('>Key</th>', $two);
    }

    public function test_every_value_cell_has_a_phone_label(): void
    {
        $app = ClientApp::factory()->create();
        $this->apiCall($app);
        $this->event($app, 'failed', ['last_error' => 'RuntimeException: nope']);

        $html = $this->page()->getContent();
        preg_match_all('#<td\b([^>]*)>#', $html, $cells);
        $labels = array_map(fn (string $attrs) => preg_match('/data-label="([^"]+)"/', $attrs, $m) === 1 ? $m[1] : null, $cells[1]);
        $this->assertSame(['Time', 'Type', 'Detail', 'Result', null, 'Time', 'Type', 'Detail', 'Result', null], $labels);
        $this->assertSame(2, substr_count($html, 'class="api-act"'));
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
