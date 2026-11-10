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
            ->assertSeeInOrder(['API keys', '2', 'Logs'])->assertDontSee('Webhooks')
            ->assertSee('Create key')->assertSee('x-page__actions', false)->getContent();
        $this->assertMatchesRegularExpression('/<main[^>]*class="x-content"/', $keys);
        $this->assertMatchesRegularExpression('/class="x-tab is-on"[^>]*aria-current="page"[^>]*>\s*API keys/', $keys);

        $this->get(route('developers.index', ['tab' => 'webhooks']))->assertRedirect(route('developers.index', ['tab' => 'keys']));
        $this->get(route('developers.index', ['tab' => 'logs']))->assertOk()
            ->assertSeeInOrder(['All', 'Failed', '0', 'API calls', 'Webhooks'])->assertSee('No activity');
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

        foreach (['keys' => ['Roll key', 'Roll webhook secret', 'Rename', 'Revoke']] as $tab => $items) {
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

    public function test_the_keys_tab_shows_the_base_url_with_copy_even_without_keys(): void
    {
        $this->actingAs($this->admin());

        $html = $this->get(route('developers.index'))->assertOk()->assertSeeInOrder(['Base URL', 'No API keys'])->getContent();
        $this->assertStringContainsString('<label class="api-row__label" for="f-base-url">Base URL</label>', $html);
        $this->assertMatchesRegularExpression('#<input type="text" id="f-base-url"[^>]*value="'.preg_quote(url('api/v1'), '#').'" readonly#', $html);
        $this->assertStringContainsString('aria-label="Copy base URL"', $html);
        $this->assertStringContainsString('class="x-copy__label x-copy__label--ok"', $html);
        $this->assertStringNotContainsString('Base URL', $this->get(route('developers.index', ['tab' => 'logs']))->getContent());
    }

    public function test_card_controls_are_named_per_key_and_labelled(): void
    {
        $app = ClientApp::factory()->create(['name' => 'Demo Store', 'webhook_url' => 'https://a.test/h']);
        $key = app(PlatformApps::class)->issueKey($app, 'K')['key'];
        $this->actingAs($this->admin());

        $html = $this->get(route('developers.index'))->assertOk()->getContent();
        $this->assertStringContainsString('aria-label="Send test webhook Demo Store"', $html);
        $this->assertStringContainsString('aria-label="Save webhook URL Demo Store"', $html);
        $this->assertStringContainsString('<label class="api-row__label" for="f-token-'.$key->id.'">API key</label>', $html);
        $this->assertStringContainsString('id="f-token-'.$key->id.'"', $html);
        $this->assertStringContainsString('<label class="api-row__label" for="f-secret-'.$app->public_id.'">Webhook secret</label>', $html);
        $this->assertStringContainsString('id="f-secret-'.$app->public_id.'"', $html);
        foreach (['View API key Demo Store', 'Hide API key Demo Store', 'Copy API key Demo Store', 'View Webhook secret Demo Store', 'Hide Webhook secret Demo Store'] as $name) {
            $this->assertSame(1, substr_count($html, 'aria-label="'.$name.'"'), $name);
        }
        // View hands focus to Hide and back; the 30-second countdown shows beside them while shown.
        $this->assertSame(2, substr_count($html, 'x-ref="viewButton"'));
        $this->assertSame(2, substr_count($html, 'x-ref="hideButton"'));
        $this->assertSame(2, substr_count($html, 'x-on:click="hide(true)"'));
        $this->assertSame(2, substr_count($html, '<span class="ac-cred__tick x-num" aria-hidden="true"'));
        $this->assertMatchesRegularExpression('/<p class="x-sr" role="status" aria-live="polite" x-text="status"><\/p>/', $html);
        $this->assertStringContainsString('x-on:credential-status=', $html);
    }

    public function test_create_form_closes_on_cancel_and_esc_back_to_the_header_button(): void
    {
        $this->actingAs($this->admin());

        $html = $this->get(route('developers.index', ['create' => 1]))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<a href="[^"]*" class="x-btn x-btn--primary[^"]*" id="create-key"/', $html);
        $close = e("creating = false; \$nextTick(() => document.getElementById('create-key')?.focus())");
        $this->assertStringContainsString('x-on:keydown.escape.prevent.stop="'.$close.'"', $html);
        $this->assertStringContainsString('x-on:click.prevent="'.$close.'">Cancel', $html);
    }

    public function test_row_menu_opens_from_the_keyboard_and_closes_when_focus_leaves(): void
    {
        $app = ClientApp::factory()->create(['name' => 'Shop']);
        app(PlatformApps::class)->issueKey($app, 'K');

        $html = $this->actingAs($this->admin())->get(route('developers.index'))->assertOk()->getContent();
        $this->assertStringContainsString('x-on:keydown="onTriggerKey($event)"', $html);
        $this->assertStringContainsString('x-on:focusout="onFocusOut($event)"', $html);
    }
}
