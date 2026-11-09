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
        return $this->actingAs($this->admin())->get(route('developers.index', ['tab' => 'keys'] + $query))->assertOk();
    }

    private function back(): string
    {
        return route('developers.index', ['tab' => 'keys']);
    }

    private function event(ClientApp $app, string $status, ?string $error = null): void
    {
        OutgoingEvent::query()->create([
            'client_app_id' => $app->id, 'type' => 'ping', 'payload' => ['x' => 1], 'status' => $status,
            'attempts' => 1, 'last_error' => $error,
        ]);
    }

    private function keyed(string $name, ?string $url = null): ClientApp
    {
        $app = ClientApp::factory()->create(['name' => $name, 'webhook_url' => $url]);
        app(PlatformApps::class)->issueKey($app, $name);

        return $app;
    }

    public function test_webhooks_tab_redirects_to_keys_and_is_gone_from_the_tabs(): void
    {
        $this->keyed('Shop');
        $this->actingAs($this->admin())->get(route('developers.index', ['tab' => 'webhooks']))->assertRedirect($this->back());
        $this->page()->assertDontSee('Webhooks<', false)->assertDontSee('Add endpoint');
    }

    public function test_cards_show_webhook_rows_status_and_masked_secret(): void
    {
        $ok = $this->keyed('Shop One', 'https://a.test/h');
        $bad = $this->keyed('Shop Two', 'https://b.test/h');
        $quiet = $this->keyed('Shop Xen', 'https://c.test/h');
        $none = $this->keyed('Shop None');
        $off = ClientApp::factory()->create(['name' => 'Shop Off', 'webhook_url' => 'https://off.test/h', 'status' => 'disabled']);
        app(PlatformApps::class)->issueKey($off, 'K');
        $this->event($ok, 'failed');
        $this->event($ok, 'delivered');
        $this->event($bad, 'delivered');
        $this->event($bad, 'pending', 'HTTP 500');
        $this->event($quiet, 'skipped', 'app disabled');
        $secret = app(PlatformApps::class)->revealWebhookSecret($ok, self::ACTOR);

        $html = $this->page()
            ->assertSeeInOrder(['Shop None', 'API key', 'Webhook URL', 'No webhook', 'Shop One', 'Webhook URL', 'Delivering', 'Webhook secret', 'Send test', 'Shop Two', 'Failing', 'Shop Xen', 'No deliveries'])
            ->assertDontSee('Shop Off')
            ->getContent();
        $this->assertStringContainsString('value="https://a.test/h"', $html);
        $this->assertStringNotContainsString('https://off.test/h', $html);
        $this->assertSame(3, substr_count($html, 'Webhook secret</span>'));
        $this->assertStringNotContainsString($secret, $html);
        $this->assertStringNotContainsString('whsec_', $html);
        $this->assertStringContainsString('action="'.route('developers.endpoints.update', $ok).'"', $html);
        $this->assertStringContainsString('action="'.route('developers.endpoints.update', $none).'"', $html);
        $decoded = html_entity_decode($html);
        $this->assertStringContainsString('Roll the webhook secret for Shop One?', $decoded);
        $this->assertStringContainsString('Remove the webhook for Shop One?', $decoded);
        $this->assertStringNotContainsString('Roll the webhook secret for Shop None?', $decoded);
        $this->assertSame(3, substr_count($html, 'Roll webhook secret</button>'));
        $this->assertStringContainsString('id="delete-'.$ok->public_id.'"', $html);
        $this->assertStringNotContainsString('id="delete-'.$none->public_id.'"', $html);
    }

    public function test_an_app_with_several_keys_shows_the_webhook_on_its_first_card_only(): void
    {
        $app = ClientApp::factory()->create(['name' => 'Multi', 'webhook_url' => 'https://a.test/h']);
        app(PlatformApps::class)->issueKey($app, 'A');
        app(PlatformApps::class)->issueKey($app, 'B');

        $html = $this->page()->assertSee('Multi · A')->assertSee('Multi · B')->getContent();
        $this->assertSame(1, substr_count($html, 'Webhook URL</label>'));
        $this->assertSame(1, substr_count($html, 'id="f-url-'.$app->public_id.'"'));
    }

    public function test_set_replace_and_remove_the_url(): void
    {
        $app = ClientApp::factory()->create(['name' => 'Shop']);
        $this->actingAs($this->admin());

        $this->put(route('developers.endpoints.update', $app), ['webhook_url' => 'https://free.test/h'])
            ->assertRedirect($this->back())->assertSessionHas('status', 'Saved');
        $this->assertSame('https://free.test/h', $app->fresh()->webhook_url);
        $this->put(route('developers.endpoints.update', $app), ['webhook_url' => 'https://z.test/h'])->assertSessionHas('status', 'Saved');
        $this->assertSame('https://z.test/h', $app->fresh()->webhook_url);

        $this->put(route('developers.endpoints.update', $app), ['webhook_url' => 'javascript:alert(1)'])->assertSessionHasErrors('webhook_url');
        $this->put(route('developers.endpoints.update', $app), ['webhook_url' => 'http://a_b.test/hook'])->assertSessionHasErrors('webhook_url');
        $this->put(route('developers.endpoints.update', $app), ['webhook_url' => ''])->assertSessionHasErrors('webhook_url');
        $this->assertSame('https://z.test/h', $app->fresh()->webhook_url);

        $this->delete(route('developers.endpoints.destroy', $app))->assertRedirect($this->back())->assertSessionHas('status', 'Deleted');
        $this->assertNull($app->fresh()->webhook_url);
        $this->assertSame('active', $app->fresh()->status);
    }

    public function test_a_rejected_url_comes_back_in_its_own_card_field(): void
    {
        $this->keyed('Other', 'https://o.test/h');
        $app = $this->keyed('Shop', 'https://a.test/h');
        $this->actingAs($this->admin());

        $this->from($this->back())->put(route('developers.endpoints.update', $app), ['app' => $app->public_id, 'webhook_url' => 'ftp://bad'])
            ->assertRedirect($this->back())->assertSessionHasErrors('webhook_url');
        $html = $this->get($this->back())->assertOk()->getContent();
        $this->assertStringContainsString('value="ftp://bad"', $html);
        $this->assertStringContainsString('value="https://o.test/h"', $html);
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
        $app = $this->keyed('Shop', 'https://a.test/h');
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
