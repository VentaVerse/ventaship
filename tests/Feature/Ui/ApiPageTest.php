<?php

namespace Tests\Feature\Ui;

use App\Models\Admin;
use App\Models\ClientApp;
use App\Models\OutgoingEvent;
use App\Platforms\PlatformApps;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ApiPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function admin(): Admin
    {
        return Admin::query()->firstOrCreate(['email' => 'ana@example.com'], ['name' => 'Ana', 'password' => 'correct-horse-battery']);
    }

    public function test_guests_are_redirected_from_every_route(): void
    {
        $app = ClientApp::factory()->create(['webhook_url' => 'https://a.test/h']);
        $key = app(PlatformApps::class)->issueKey($app, 'K')['key'];
        $event = OutgoingEvent::query()->create(['client_app_id' => $app->id, 'type' => 'ping', 'payload' => [], 'status' => 'failed', 'attempts' => 1]);
        $login = route('login');

        $this->get(route('developers.index'))->assertRedirect($login);
        $this->get('/platforms')->assertRedirect($login);
        $this->post(route('developers.keys.store'), ['name' => 'X'])->assertRedirect($login);
        $this->post(route('developers.keys.roll', $app), ['key' => $key->id])->assertRedirect($login);
        $this->put(route('developers.keys.rename', $app), ['name' => 'X'])->assertRedirect($login);
        $this->post(route('developers.keys.revoke', $app), ['key' => $key->id])->assertRedirect($login);
        $this->post(route('developers.reveal', [$app, 'token:'.$key->id]))->assertRedirect($login);
        $this->post(route('developers.reveal', [$app, 'webhook']))->assertRedirect($login);
        $this->post(route('developers.endpoints.store'), ['app' => $app->public_id, 'webhook_url' => 'https://b.test/h'])->assertRedirect($login);
        $this->put(route('developers.endpoints.update', $app), ['webhook_url' => 'https://b.test/h'])->assertRedirect($login);
        $this->delete(route('developers.endpoints.destroy', $app))->assertRedirect($login);
        $this->post(route('developers.endpoints.test', $app))->assertRedirect($login);
        $this->post(route('developers.endpoints.secret', $app))->assertRedirect($login);
        $this->post(route('developers.events.resend', $event))->assertRedirect($login);

        $this->assertNotNull($key->fresh());
        $this->assertSame('https://a.test/h', $app->fresh()->webhook_url);
    }

    public function test_every_tab_renders_with_counts(): void
    {
        $one = ClientApp::factory()->create(['name' => 'Shop One', 'webhook_url' => 'https://a.test/h']);
        $two = ClientApp::factory()->create(['name' => 'Shop Two']);
        app(PlatformApps::class)->issueKey($one, 'K');
        app(PlatformApps::class)->issueKey($two, 'K');
        $this->actingAs($this->admin());

        $keys = $this->get(route('developers.index'))->assertOk()
            ->assertSee('<title>API · ', false)
            ->assertSeeInOrder(['API keys', '2', 'Webhooks', '1', 'Logs'])
            ->assertSee('Create key')->getContent();
        $this->assertMatchesRegularExpression('/<main[^>]*class="x-content"/', $keys);
        $this->assertMatchesRegularExpression('/class="x-tab is-on"[^>]*aria-current="page"[^>]*>\s*API keys/', $keys);

        $this->get(route('developers.index', ['tab' => 'webhooks']))->assertOk()
            ->assertSee('Endpoints')->assertSee('Add endpoint')->assertSee('https://a.test/h');
        $this->get(route('developers.index', ['tab' => 'logs']))->assertOk()
            ->assertSee('All')->assertSee('API calls')->assertSee('Webhook deliveries')->assertSee('No activity');
        // An unknown tab falls back to keys.
        $this->get(route('developers.index', ['tab' => 'nope']))->assertOk()->assertSee('Create key');
    }

    public function test_old_urls_redirect(): void
    {
        $app = ClientApp::factory()->create();
        $this->actingAs($this->admin());

        $this->get('/platforms')->assertRedirect(route('developers.index'));
        $this->get('/platforms/new')->assertRedirect(route('developers.index', ['tab' => 'keys', 'create' => 1]));
        $this->get('/platforms/'.$app->public_id)->assertRedirect(route('developers.index'));
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('apps.index'));
    }

    public function test_sidebar_shows_api_under_developers(): void
    {
        $html = $this->actingAs($this->admin())->get(route('developers.index'))->assertOk()
            ->assertDontSee('Connected platforms')->getContent();
        $this->assertMatchesRegularExpression('/Developers.*aria-current="page"[^>]*>\s*<svg.*?<span>API<\/span>/s', $html);
    }

    public function test_row_menus_are_accessible_buttons_with_a_real_icon(): void
    {
        $app = ClientApp::factory()->create(['name' => 'Shop', 'webhook_url' => 'https://a.test/h']);
        app(PlatformApps::class)->issueKey($app, 'K');
        $this->actingAs($this->admin());

        foreach (['keys' => ['Roll key', 'Rename', 'Revoke'], 'webhooks' => ['Send test', 'Edit', 'Roll secret', 'Delete']] as $tab => $items) {
            $html = $this->get(route('developers.index', ['tab' => $tab]))->assertOk()->assertSeeInOrder($items)->getContent();
            $this->assertMatchesRegularExpression('/<button[^>]*aria-haspopup="menu"[^>]*>/', $html, $tab);
            $this->assertStringContainsString('aria-label="Actions for Shop"', $html);
            $this->assertStringContainsString('x-bind:aria-expanded', $html);
            $this->assertStringContainsString('role="menu"', $html);
            $this->assertStringContainsString('role="menuitem"', $html);
            $this->assertStringNotContainsString('⋯', $html);
            $this->assertMatchesRegularExpression('/<svg[^>]*>\s*<circle cx="5" cy="12"/', $html);
            $this->assertStringNotContainsString('style="', $html);
            $this->assertDoesNotMatchRegularExpression('/class="[^"]*\b(ad-|ads|banner|sponsor|promo)/', $html);
        }
    }
}
