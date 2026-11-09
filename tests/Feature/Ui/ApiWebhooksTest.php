<?php

namespace Tests\Feature\Ui;

use App\Models\Admin;
use App\Models\AuditEntry;
use App\Models\ClientApp;
use App\Models\OutgoingEvent;
use App\Platforms\PlatformApps;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ApiWebhooksTest extends TestCase
{
    use RefreshDatabase;

    private const ACTOR = ['type' => 'admin', 'id' => 1, 'ip' => null];

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
        return $this->actingAs($this->admin())->get(route('developers.index', ['tab' => 'webhooks'] + $query))->assertOk();
    }

    private function back(): string
    {
        return route('developers.index', ['tab' => 'webhooks']);
    }

    private function event(ClientApp $app, string $status, ?string $error = null): void
    {
        OutgoingEvent::query()->create([
            'client_app_id' => $app->id, 'type' => 'ping', 'payload' => ['x' => 1], 'status' => $status,
            'attempts' => 1, 'last_error' => $error,
        ]);
    }

    public function test_empty_state(): void
    {
        $this->page()->assertSee('No endpoints')->assertSee('Add endpoint');
    }

    public function test_endpoints_list_status_and_masked_secret(): void
    {
        $ok = ClientApp::factory()->create(['name' => 'Shop One', 'webhook_url' => 'https://a.test/h']);
        $bad = ClientApp::factory()->create(['name' => 'Shop Two', 'webhook_url' => 'https://b.test/h']);
        $quiet = ClientApp::factory()->create(['name' => 'Shop Xen', 'webhook_url' => 'https://c.test/h']);
        $off = ClientApp::factory()->create(['name' => 'Shop Off', 'webhook_url' => 'https://off.test/h', 'status' => 'disabled']);
        ClientApp::factory()->create(['name' => 'No Hook']);
        $this->event($ok, 'failed');
        $this->event($ok, 'delivered');
        $this->event($bad, 'delivered');
        $this->event($bad, 'pending', 'HTTP 500');
        $this->event($quiet, 'skipped', 'app disabled');
        $secret = app(PlatformApps::class)->revealWebhookSecret($ok, self::ACTOR);

        $html = $this->page()
            ->assertSeeInOrder(['Endpoint', 'Key', 'Status', 'Signing secret'])
            ->assertSeeInOrder(['https://a.test/h', 'Shop One', 'Delivering', substr($secret, -4), 'https://b.test/h', 'Shop Two', 'Failing', 'https://c.test/h', 'Shop Xen', 'No deliveries'])
            ->assertDontSee('https://off.test/h')
            ->getContent();
        $this->assertStringNotContainsString($secret, $html);
        $this->assertStringNotContainsString('whsec_', $html);
        $this->assertStringContainsString('Roll the signing secret for Shop One?', html_entity_decode($html));
        $this->assertStringContainsString('Delete the endpoint for Shop One?', html_entity_decode($html));
    }

    public function test_add_endpoint_offers_only_keys_without_one(): void
    {
        ClientApp::factory()->create(['name' => 'Has Hook', 'webhook_url' => 'https://a.test/h']);
        $free = ClientApp::factory()->create(['name' => 'Free App']);
        ClientApp::factory()->create(['name' => 'Off App', 'status' => 'disabled']);

        $html = $this->page(['add' => 1])->getContent();
        $this->assertStringContainsString('x-data="{ adding: true }"', $html);
        $this->assertStringContainsString('<option value="'.$free->public_id.'"', $html);
        $this->assertStringNotContainsString('>Has Hook</option>', $html);
        $this->assertStringNotContainsString('Off App', $html);

        $this->post(route('developers.endpoints.store'), ['app' => $free->public_id, 'webhook_url' => 'https://free.test/h'])
            ->assertRedirect($this->back())->assertSessionHas('status', 'Added');
        $this->assertSame('https://free.test/h', $free->fresh()->webhook_url);
    }

    public function test_add_endpoint_validation(): void
    {
        $free = ClientApp::factory()->create(['name' => 'Free']);
        $hooked = ClientApp::factory()->create(['webhook_url' => 'https://a.test/h']);
        $this->actingAs($this->admin());

        $this->post(route('developers.endpoints.store'), ['app' => $free->public_id, 'webhook_url' => 'ftp://x.test'])
            ->assertRedirect(route('developers.index', ['tab' => 'webhooks', 'add' => 1]))->assertSessionHasErrors('webhook_url');
        $this->post(route('developers.endpoints.store'), ['app' => $free->public_id, 'webhook_url' => 'http://a_b.test/hook'])->assertSessionHasErrors('webhook_url');
        $this->post(route('developers.endpoints.store'), ['app' => 'app_nope', 'webhook_url' => 'https://x.test'])->assertSessionHasErrors('app');
        $this->post(route('developers.endpoints.store'), ['app' => $hooked->public_id, 'webhook_url' => 'https://x.test'])->assertSessionHasErrors('app');
        $this->assertNull($free->fresh()->webhook_url);
        $this->assertSame('https://a.test/h', $hooked->fresh()->webhook_url);
    }

    public function test_edit_and_delete_endpoint(): void
    {
        $app = ClientApp::factory()->create(['name' => 'Shop', 'webhook_url' => 'https://a.test/h']);

        $html = $this->page(['edit' => $app->public_id])->getContent();
        $this->assertStringContainsString('x-data="{ editing: true }"', $html);
        $this->assertStringContainsString('action="'.route('developers.endpoints.update', $app).'"', $html);

        $this->put(route('developers.endpoints.update', $app), ['webhook_url' => 'https://z.test/h'])
            ->assertRedirect($this->back())->assertSessionHas('status', 'Saved');
        $this->assertSame('https://z.test/h', $app->fresh()->webhook_url);
        $this->put(route('developers.endpoints.update', $app), ['webhook_url' => 'javascript:alert(1)'])->assertSessionHasErrors('webhook_url');
        $this->put(route('developers.endpoints.update', $app), ['webhook_url' => ''])->assertSessionHasErrors('webhook_url');

        $this->delete(route('developers.endpoints.destroy', $app))->assertRedirect($this->back())->assertSessionHas('status', 'Deleted');
        $this->assertNull($app->fresh()->webhook_url);
        $this->assertSame('active', $app->fresh()->status);
    }

    public function test_roll_secret(): void
    {
        $app = ClientApp::factory()->create(['webhook_url' => 'https://a.test/h']);
        $old = app(PlatformApps::class)->revealWebhookSecret($app, self::ACTOR);

        $this->actingAs($this->admin())->post(route('developers.endpoints.secret', $app))
            ->assertRedirect($this->back())->assertSessionHas('status', 'Rolled');

        $new = $this->actingAs($this->admin())->postJson(route('developers.reveal', [$app, 'webhook']))->assertOk()->json('value');
        $this->assertNotSame($old, $new);
        $this->assertStringNotContainsString($new, serialize(session()->all()));
        $html = $this->page()->getContent();
        $this->assertStringNotContainsString($new, $html);
        $this->assertSame(1, AuditEntry::query()->where('action', 'client_app.webhook_secret_regenerated')->count());
    }

    public function test_unset_secret_shows_not_set_and_can_be_rolled(): void
    {
        $app = ClientApp::factory()->create(['webhook_url' => 'https://a.test/h']);
        DB::table('client_apps')->where('id', $app->id)->update(['webhook_secret' => null]);

        $this->page()->assertSee('Not set');
        $this->post(route('developers.endpoints.secret', $app))->assertSessionHas('status', 'Rolled');
        $this->actingAs($this->admin())->postJson(route('developers.reveal', [$app, 'webhook']))->assertOk();
    }

    public function test_send_test_results(): void
    {
        $app = ClientApp::factory()->create(['webhook_url' => 'https://shop.test/hook']);
        $this->actingAs($this->admin());

        Http::fake(['shop.test/*' => Http::sequence()->push('ok', 200)->push('no', 503)]);
        $this->post(route('developers.endpoints.test', $app))->assertRedirect($this->back())->assertSessionHas('status', 'Delivered · HTTP 200');
        $this->post(route('developers.endpoints.test', $app))->assertSessionHasErrors(['ping' => 'Failed · HTTP 503']);
    }

    public function test_send_test_no_answer(): void
    {
        Http::fake(['shop.test/*' => fn () => throw new ConnectionException('refused')]);
        $app = ClientApp::factory()->create(['webhook_url' => 'https://shop.test/hook']);

        $this->actingAs($this->admin())->post(route('developers.endpoints.test', $app))
            ->assertSessionHasErrors(['ping' => 'No answer from the platform.']);
    }
}
