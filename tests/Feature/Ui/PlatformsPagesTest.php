<?php

namespace Tests\Feature\Ui;

use App\Models\Admin;
use App\Models\ApiRequest;
use App\Models\AuditEntry;
use App\Platforms\PlatformApps;
use App\Models\ClientApp;
use App\Models\OutgoingEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PlatformsPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function admin(): Admin
    {
        return Admin::query()->create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'correct-horse-battery']);
    }

    private function event(ClientApp $app, string $status, ?string $error = null): OutgoingEvent
    {
        return OutgoingEvent::query()->create([
            'client_app_id' => $app->id, 'type' => 'ping', 'payload' => ['x' => 1], 'status' => $status,
            'attempts' => 1, 'last_error' => $error,
        ]);
    }

    public function test_guests_are_redirected(): void
    {
        $app = ClientApp::factory()->create();
        $this->get(route('apps.index'))->assertRedirect(route('login'));
        $this->get(route('apps.create'))->assertRedirect(route('login'));
        $this->post(route('apps.store'), ['name' => 'X'])->assertRedirect(route('login'));
        $this->get(route('apps.show', $app))->assertRedirect(route('login'));
        $this->put(route('apps.update', $app), ['name' => 'X'])->assertRedirect(route('login'));
    }

    public function test_empty_state_and_sidebar_item(): void
    {
        $this->actingAs($this->admin())->get(route('apps.index'))->assertOk()
            ->assertSee('No platforms')->assertSee('Connected platforms')->assertSee('Add platform')
            ->assertSee(route('apps.create'), false);
    }

    public function test_list_rows_and_badges(): void
    {
        $this->travelTo('2026-11-09 12:00:00');
        $ok = ClientApp::factory()->create(['name' => 'Shop One', 'webhook_url' => 'https://a.test/h']);
        $bad = ClientApp::factory()->create(['name' => 'Shop Two', 'webhook_url' => 'https://b.test/h', 'status' => 'disabled']);
        $none = ClientApp::factory()->create(['name' => 'Shop Zed']);
        $quiet = ClientApp::factory()->create(['name' => 'Shop Yak', 'webhook_url' => 'https://c.test/h']);
        $skipOnly = ClientApp::factory()->create(['name' => 'Shop Xen', 'webhook_url' => 'https://d.test/h']);
        $this->event($skipOnly, 'skipped', 'app disabled');
        $this->event($ok, 'failed');
        $this->event($ok, 'delivered');
        $this->event($bad, 'delivered');
        $this->event($bad, 'pending', 'HTTP 500');
        app(PlatformApps::class)->issueKey($ok, 'one');
        app(PlatformApps::class)->issueKey($ok, 'two');
        $gone = app(PlatformApps::class)->issueKey($ok, 'gone')['key'];
        app(PlatformApps::class)->revokeKey($gone);
        ApiRequest::query()->create(['client_app_id' => $ok->id, 'method' => 'GET', 'path' => '/x', 'status' => 200, 'created_at' => now()->subHour()]);

        $html = $this->actingAs($this->admin())->get(route('apps.index'))->assertOk()
            ->assertSeeInOrder(['Shop One', 'Active', 'Delivering', 'Shop Two', 'Disabled', 'Failing', 'Shop Xen', 'No deliveries', 'Shop Yak', 'No deliveries', 'Shop Zed', 'No webhook'])
            ->assertSee('2 keys')
            ->assertSee(route('apps.show', $ok), false)
            ->getContent();
        $this->assertSame(5, substr_count($html, '>Open</a>'));
        $this->assertStringContainsString('1 hour ago', $html);
    }

    public function test_create_form_and_store(): void
    {
        $this->actingAs($this->admin())->get(route('apps.create'))->assertOk()->assertSee('name="webhook_url"', false);

        $res = $this->post(route('apps.store'), ['name' => 'Shop', 'webhook_url' => 'https://a.test/h']);
        $app = ClientApp::query()->sole();
        $res->assertRedirect(route('apps.show', $app))->assertSessionHas('status', 'Added');
        $this->assertSame('https://a.test/h', $app->webhook_url);
        $this->assertSame('admin', AuditEntry::query()->where('action', 'client_app.created')->sole()->actor_type);
    }

    public function test_store_validation(): void
    {
        $this->actingAs($this->admin());
        $this->post(route('apps.store'), ['name' => ''])->assertSessionHasErrors('name');
        $this->post(route('apps.store'), ['name' => 'A', 'webhook_url' => 'ftp://x.test'])->assertSessionHasErrors('webhook_url');
        $this->post(route('apps.store'), ['name' => 'A', 'webhook_url' => 'not a url'])->assertSessionHasErrors('webhook_url');
        $this->assertSame(0, ClientApp::count());
    }

    public function test_show_never_leaks_secrets(): void
    {
        $app = ClientApp::factory()->create(['name' => 'Shop', 'webhook_url' => 'https://a.test/h']);
        $issued = app(PlatformApps::class)->issueKey($app, 'main');
        $html = $this->actingAs($this->admin())->get(route('apps.show', $app))->assertOk()
            ->assertSee('Shop')->assertSee('https://a.test/h')->assertSee('Discard')->assertSee('Save')
            ->assertSee('<main', false)->getContent();
        $this->assertMatchesRegularExpression('/<main[^>]*class="x-content x-content--form"/', $html);
        $this->assertStringNotContainsString($app->webhook_secret, $html);
        $this->assertStringNotContainsString('whsec_', $html);
        $this->assertStringNotContainsString(explode('|', $issued['plaintext_key'], 2)[1], $html);
    }

    public function test_update_and_disable(): void
    {
        $app = ClientApp::factory()->create(['name' => 'Shop']);
        $this->actingAs($this->admin());

        $this->put(route('apps.update', $app), ['name' => 'Renamed', 'webhook_url' => 'https://z.test/h', 'active' => '1'])
            ->assertRedirect(route('apps.show', $app))->assertSessionHas('status', 'Saved');
        $app->refresh();
        $this->assertSame('Renamed', $app->name);
        $this->assertSame('active', $app->status);

        $this->put(route('apps.update', $app), ['name' => 'Renamed', 'webhook_url' => '', 'active' => '0']);
        $app->refresh();
        $this->assertSame('disabled', $app->status);
        $this->assertNull($app->webhook_url);
    }

    public function test_service_refusal_maps_to_the_webhook_field(): void
    {
        $app = ClientApp::factory()->create(['name' => 'Shop']);
        $this->actingAs($this->admin());
        $url = 'http://a_b.test/hook';

        $this->post(route('apps.store'), ['name' => 'A', 'webhook_url' => $url])->assertSessionHasErrors('webhook_url');
        $this->put(route('apps.update', $app), ['name' => 'A', 'webhook_url' => $url, 'active' => '1'])->assertSessionHasErrors('webhook_url');
        $this->assertSame(1, ClientApp::count());
    }

    public function test_update_validation(): void
    {
        $app = ClientApp::factory()->create(['name' => 'Shop']);
        $this->actingAs($this->admin());
        $this->put(route('apps.update', $app), ['name' => '', 'active' => '1'])->assertSessionHasErrors('name');
        $this->put(route('apps.update', $app), ['name' => 'S', 'webhook_url' => 'javascript:alert(1)', 'active' => '1'])->assertSessionHasErrors('webhook_url');
        $this->assertSame('Shop', $app->fresh()->name);
    }
}
