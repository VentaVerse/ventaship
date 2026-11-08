<?php

namespace Tests\Feature\Ui;

use App\Api\Signature;
use App\Models\Admin;
use App\Models\ApiKey;
use App\Models\AuditEntry;
use App\Models\ClientApp;
use App\Platforms\PlatformApps;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PlatformKeysTest extends TestCase
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

    private function signedGet(string $uri, string $key, string $secret): TestResponse
    {
        $ts = time();

        return $this->call('GET', $uri, [], [], [], $this->transformHeadersToServerVars([
            'Authorization' => 'Bearer '.$key,
            'X-Timestamp' => (string) $ts,
            'X-Signature' => Signature::sign($secret, $ts, 'GET', $uri, ''),
            'Accept' => 'application/json',
        ]));
    }

    private function reveal(ClientApp $app, string $what): TestResponse
    {
        return $this->actingAs($this->admin())->postJson(route('apps.reveal', [$app, $what]));
    }

    /** A string as @js() writes it into an attribute. */
    private function inJs(string $value): string
    {
        return str_replace('/', '\\\\\\/', $value);
    }

    private function legacyKey(ClientApp $app): ApiKey
    {
        $plain = 'vsk_'.str_repeat('L', 40);

        return ApiKey::query()->create([
            'client_app_id' => $app->id, 'name' => 'Old', 'key_prefix' => substr($plain, 0, 8),
            'key_hash' => hash('sha256', $plain), 'signing_secret' => str_repeat('S', 48),
        ]);
    }

    public function test_guests_are_redirected_from_every_new_route(): void
    {
        $app = ClientApp::factory()->create();
        $key = app(PlatformApps::class)->issueKey($app, 'K')['key'];

        $this->post(route('apps.keys.store', $app), ['key_name' => 'X'])->assertRedirect(route('login'));
        $this->post(route('apps.keys.revoke', [$app, $key->id]))->assertRedirect(route('login'));
        $this->post(route('apps.reveal', [$app, 'key:'.$key->key_prefix]))->assertRedirect(route('login'));
        $this->post(route('apps.reveal', [$app, 'webhook']))->assertRedirect(route('login'));
        $this->post(route('apps.webhook.regenerate', $app))->assertRedirect(route('login'));
        $this->post(route('apps.ping', $app))->assertRedirect(route('login'));
        $this->assertNull($key->fresh()->revoked_at);
    }

    public function test_issue_flags_prefix_only_and_revealed_key_passes_the_api(): void
    {
        $app = ClientApp::factory()->create();

        $res = $this->actingAs($this->admin())->post(route('apps.keys.store', $app), ['key_name' => 'Storefront']);

        $sessionAfterIssue = serialize(session()->all());
        $key = ApiKey::query()->sole();
        $res->assertRedirect(route('apps.show', $app).'#keys')->assertSessionHas('issued_key', $key->key_prefix);
        $this->assertSame('Storefront', $key->name);

        $first = $this->reveal($app, 'key:'.$key->key_prefix)->assertOk();
        $this->assertStringContainsString('no-store', (string) $first->headers->get('Cache-Control'));
        $plainKey = $first->json('value');
        $secret = $this->reveal($app, 'secret:'.$key->key_prefix)->assertOk()->json('value');
        $this->assertStringStartsWith($key->key_prefix, $plainKey);

        // The session from the issue request holds no plaintext.
        foreach ([$sessionAfterIssue, serialize(session()->all())] as $session) {
            $this->assertStringNotContainsString($plainKey, $session);
            $this->assertStringNotContainsString($secret, $session);
        }

        // The redirected page asks for the reveal; its HTML has neither value.
        $html = $this->withSession(['issued_key' => $key->key_prefix])->get(route('apps.show', $app))->assertOk()->getContent();
        $this->assertStringNotContainsString($plainKey, $html);
        $this->assertStringNotContainsString($secret, $html);
        $this->assertStringContainsString('\u0022autoView\u0022:true', $html);
        $this->assertStringContainsString($this->inJs(route('apps.reveal', [$app, 'key:'.$key->key_prefix])), $html);

        $this->signedGet('/api/v1/ping', $plainKey, $secret)->assertOk();
        $this->assertSame(2, AuditEntry::query()->where('action', 'api_key.viewed')->count());
        $audits = json_encode(DB::table('audit_entries')->get()->all());
        $this->assertStringNotContainsString($plainKey, $audits);
        $this->assertStringNotContainsString($secret, $audits);
    }

    public function test_issue_name_defaults_to_key_n(): void
    {
        $app = ClientApp::factory()->create();
        app(PlatformApps::class)->issueKey($app, 'First');

        $this->actingAs($this->admin())->get(route('apps.show', $app))->assertOk()->assertSee('value="Key 2"', false);
        $this->actingAs($this->admin())->post(route('apps.keys.store', $app), ['key_name' => ''])->assertSessionHasErrors('key_name');
        $this->assertSame(1, ApiKey::query()->count());
    }

    public function test_page_lists_keys_masked_without_plaintext(): void
    {
        $this->travelTo('2026-11-09 12:00:00');
        $app = ClientApp::factory()->create(['webhook_url' => 'https://shop.test/hook']);
        $issued = app(PlatformApps::class)->issueKey($app, 'Storefront');
        $gone = app(PlatformApps::class)->issueKey($app, 'Gone');
        app(PlatformApps::class)->revokeKey($gone['key']);
        $webhookSecret = app(PlatformApps::class)->revealWebhookSecret($app, self::ACTOR);

        $html = $this->actingAs($this->admin())->get(route('apps.show', $app))->assertOk()
            ->assertSeeInOrder(['Keys', 'Storefront', $issued['key']->key_prefix, 'Active', 'API key', 'Signing secret', 'Gone', 'Revoked', 'Webhook', 'Secret', 'Send test'])
            ->assertSee('Issue key')
            ->assertSee(substr($issued['plaintext_key'], -4))
            ->getContent();

        foreach ([$issued['plaintext_key'], $issued['secret'], $gone['plaintext_key'], $gone['secret'], $webhookSecret] as $plain) {
            $this->assertStringNotContainsString($plain, $html);
        }
        $this->assertStringContainsString('Revoke key '.$issued['key']->key_prefix.'?', html_entity_decode($html));
        $this->assertStringNotContainsString('Revoke key '.$gone['key']->key_prefix.'?', html_entity_decode($html));
        $this->assertStringNotContainsString('autoView\u0022:true', $html);
    }

    public function test_legacy_key_shows_text_and_revoke_only(): void
    {
        $app = ClientApp::factory()->create();
        $old = $this->legacyKey($app);

        $html = $this->actingAs($this->admin())->get(route('apps.show', $app))->assertOk()
            ->assertSee('Issued before keys were viewable')
            ->assertDontSee('Signing secret')
            ->getContent();
        $this->assertStringContainsString('Revoke key '.$old->key_prefix.'?', html_entity_decode($html));
        $this->assertStringNotContainsString($this->inJs(route('apps.reveal', [$app, 'key:'.$old->key_prefix])), $html);

        $this->reveal($app, 'key:'.$old->key_prefix)->assertStatus(422)->assertJson(['message' => 'Issued before keys were viewable.']);
    }

    public function test_revoke_then_reveal_is_refused(): void
    {
        $app = ClientApp::factory()->create();
        $issued = app(PlatformApps::class)->issueKey($app, 'K');
        $key = $issued['key'];

        $this->actingAs($this->admin())->post(route('apps.keys.revoke', [$app, $key->id]))
            ->assertRedirect(route('apps.show', $app).'#keys')->assertSessionHas('status', 'Revoked');
        $this->assertNotNull($key->fresh()->revoked_at);

        $res = $this->reveal($app, 'key:'.$key->key_prefix)->assertStatus(422);
        $this->assertArrayNotHasKey('value', $res->json());
        $this->reveal($app, 'secret:'.$key->key_prefix)->assertStatus(422);
        $this->signedGet('/api/v1/ping', $issued['plaintext_key'], $issued['secret'])->assertUnauthorized();

        $this->actingAs($this->admin())->post(route('apps.keys.revoke', [$app, $key->id]))->assertSessionHasErrors('keys');
    }

    public function test_other_apps_keys_are_not_found(): void
    {
        $app = ClientApp::factory()->create();
        $other = ClientApp::factory()->create();
        $theirs = app(PlatformApps::class)->issueKey($other, 'Theirs')['key'];

        $this->reveal($app, 'key:'.$theirs->key_prefix)->assertNotFound();
        $this->reveal($app, 'secret:'.$theirs->key_prefix)->assertNotFound();
        $this->reveal($app, 'key:nothing1')->assertNotFound();
        $this->actingAs($this->admin())->post(route('apps.keys.revoke', [$app, $theirs->id]))->assertNotFound();
        $this->assertNull($theirs->fresh()->revoked_at);
        $this->assertSame(0, AuditEntry::query()->where('action', 'api_key.viewed')->count());
    }

    public function test_unknown_what_is_not_found(): void
    {
        $app = ClientApp::factory()->create();
        $this->actingAs($this->admin())->post(route('apps.reveal', [$app, 'nope']))->assertNotFound();
        $this->actingAs($this->admin())->post('/platforms/'.$app->public_id.'/reveal/key:')->assertNotFound();
    }

    public function test_webhook_secret_reveal_and_regenerate(): void
    {
        $app = ClientApp::factory()->create();
        $old = $this->reveal($app, 'webhook')->assertOk()->assertHeader('Pragma', 'no-cache')->json('value');
        $this->assertSame(app(PlatformApps::class)->revealWebhookSecret($app->fresh(), self::ACTOR), $old);

        $res = $this->actingAs($this->admin())->post(route('apps.webhook.regenerate', $app))
            ->assertRedirect(route('apps.show', $app).'#webhook')->assertSessionHas('status', 'Regenerated');

        $new = $this->reveal($app, 'webhook')->assertOk()->json('value');
        $this->assertNotSame($old, $new);
        $this->assertStringNotContainsString($new, serialize(session()->all()));
        $html = $this->actingAs($this->admin())->get(route('apps.show', $app))->getContent();
        $this->assertStringNotContainsString($new, $html);
        $this->assertStringNotContainsString($old, $html);
        $this->assertSame(1, AuditEntry::query()->where('action', 'client_app.webhook_secret_regenerated')->count());
    }

    public function test_reveal_is_throttled(): void
    {
        $app = ClientApp::factory()->create();
        for ($i = 0; $i < 10; $i++) {
            $this->reveal($app, 'webhook')->assertOk();
        }
        $this->reveal($app, 'webhook')->assertStatus(429);
    }

    public function test_ping_delivered(): void
    {
        Http::fake(['shop.test/*' => Http::response('ok', 200)]);
        $app = ClientApp::factory()->create(['webhook_url' => 'https://shop.test/hook']);

        $this->actingAs($this->admin())->post(route('apps.ping', $app))
            ->assertRedirect(route('apps.show', $app).'#webhook')->assertSessionHas('status', 'Delivered · HTTP 200');
        Http::assertSentCount(1);
    }

    public function test_ping_no_answer(): void
    {
        Http::fake(['shop.test/*' => fn () => throw new ConnectionException('refused')]);
        $app = ClientApp::factory()->create(['webhook_url' => 'https://shop.test/hook']);

        $this->actingAs($this->admin())->post(route('apps.ping', $app))
            ->assertRedirect(route('apps.show', $app).'#webhook')->assertSessionHasErrors(['ping' => 'No answer from the platform.']);
    }

    public function test_ping_http_failure(): void
    {
        Http::fake(['shop.test/*' => Http::response('no', 503)]);
        $app = ClientApp::factory()->create(['webhook_url' => 'https://shop.test/hook']);

        $this->actingAs($this->admin())->post(route('apps.ping', $app))
            ->assertRedirect(route('apps.show', $app).'#webhook')->assertSessionHasErrors(['ping' => 'Failed · HTTP 503']);
    }

    public function test_ping_without_url_flashes_service_message(): void
    {
        Http::fake();
        $app = ClientApp::factory()->create();

        $this->actingAs($this->admin())->post(route('apps.ping', $app))->assertSessionHasErrors(['ping' => 'No webhook URL.']);
        Http::assertNothingSent();
    }
}
