<?php

namespace Tests\Feature\Ui;

use App\Models\Admin;
use App\Models\ApiToken;
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

    private function bearerGet(string $uri, string $token): TestResponse
    {
        return $this->call('GET', $uri, [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
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

    /** A key moved from before Sanctum without an encrypted copy: only its hash exists. */
    private function legacyKey(ClientApp $app): ApiToken
    {
        $plain = 'vsk_'.str_repeat('L', 40);

        return $app->tokens()->create([
            'name' => 'Old', 'prefix' => substr($plain, 0, 8), 'token' => hash('sha256', $plain), 'abilities' => ['*'],
        ]);
    }

    public function test_guests_are_redirected_from_every_new_route(): void
    {
        $app = ClientApp::factory()->create();
        $key = app(PlatformApps::class)->issueKey($app, 'K')['key'];

        $this->post(route('apps.keys.store', $app), ['key_name' => 'X'])->assertRedirect(route('login'));
        $this->post(route('apps.keys.revoke', [$app, $key->id]))->assertRedirect(route('login'));
        $this->post(route('apps.reveal', [$app, 'token:'.$key->id]))->assertRedirect(route('login'));
        $this->post(route('apps.reveal', [$app, 'webhook']))->assertRedirect(route('login'));
        $this->post(route('apps.webhook.regenerate', $app))->assertRedirect(route('login'));
        $this->post(route('apps.ping', $app))->assertRedirect(route('login'));
        $this->assertNotNull($key->fresh());
    }

    public function test_issue_flags_id_only_and_revealed_token_passes_the_api(): void
    {
        $app = ClientApp::factory()->create();

        $res = $this->actingAs($this->admin())->post(route('apps.keys.store', $app), ['key_name' => 'Storefront']);

        $sessionAfterIssue = serialize(session()->all());
        $key = ApiToken::query()->sole();
        $res->assertRedirect(route('apps.show', $app).'#keys')->assertSessionHas('issued_key', $key->id);
        $this->assertSame('Storefront', $key->name);

        $first = $this->reveal($app, 'token:'.$key->id)->assertOk();
        $this->assertStringContainsString('no-store', (string) $first->headers->get('Cache-Control'));
        $plain = $first->json('value');
        $this->assertStringStartsWith($key->id.'|'.$key->prefix, $plain);
        $secretPart = explode('|', $plain, 2)[1];

        // The session from the issue request holds no plaintext.
        foreach ([$sessionAfterIssue, serialize(session()->all())] as $session) {
            $this->assertStringNotContainsString($secretPart, $session);
        }

        // The redirected page asks for the reveal; its HTML has no token.
        $html = $this->withSession(['issued_key' => $key->id])->get(route('apps.show', $app))->assertOk()->getContent();
        $this->assertStringNotContainsString($secretPart, $html);
        $this->assertStringContainsString('\u0022autoView\u0022:true', $html);
        $this->assertStringContainsString($this->inJs(route('apps.reveal', [$app, 'token:'.$key->id])), $html);

        $this->bearerGet('/api/v1/ping', $plain)->assertOk()->assertJsonPath('app.id', $app->public_id);
        $this->assertSame(1, AuditEntry::query()->where('action', 'api_key.viewed')->count());
        $audits = json_encode(DB::table('audit_entries')->get()->all());
        $this->assertStringNotContainsString($secretPart, $audits);
    }

    public function test_issue_name_defaults_to_key_n(): void
    {
        $app = ClientApp::factory()->create();
        app(PlatformApps::class)->issueKey($app, 'First');

        $this->actingAs($this->admin())->get(route('apps.show', $app))->assertOk()->assertSee('value="Key 2"', false);
        $this->actingAs($this->admin())->post(route('apps.keys.store', $app), ['key_name' => ''])->assertSessionHasErrors('key_name');
        $this->assertSame(1, ApiToken::query()->count());
    }

    public function test_page_lists_tokens_masked_without_plaintext(): void
    {
        $this->travelTo('2026-11-09 12:00:00');
        $app = ClientApp::factory()->create(['webhook_url' => 'https://shop.test/hook']);
        $issued = app(PlatformApps::class)->issueKey($app, 'Storefront');
        $gone = app(PlatformApps::class)->issueKey($app, 'Gone');
        app(PlatformApps::class)->revokeKey($gone['key']);
        $webhookSecret = app(PlatformApps::class)->revealWebhookSecret($app, self::ACTOR);

        $html = $this->actingAs($this->admin())->get(route('apps.show', $app))->assertOk()
            ->assertSeeInOrder(['Keys', 'Storefront', $issued['key']->prefix, 'API token', 'Webhook', 'Secret', 'Send test'])
            ->assertSee('Issue key')
            ->assertSee(substr($issued['plaintext_key'], -4))
            ->assertDontSee('Signing secret')
            ->assertDontSee('Gone')
            ->getContent();

        foreach ([explode('|', $issued['plaintext_key'])[1], explode('|', $gone['plaintext_key'])[1], $webhookSecret] as $plain) {
            $this->assertStringNotContainsString($plain, $html);
        }
        $this->assertStringContainsString('Revoke key '.$issued['key']->prefix.'?', html_entity_decode($html));
        $this->assertStringContainsString('data-label="Created"', $html);
        $this->assertStringContainsString('data-label="Last used"', $html);
        $this->assertStringNotContainsString('autoView\u0022:true', $html);
    }

    public function test_legacy_key_shows_text_and_revoke_only(): void
    {
        $app = ClientApp::factory()->create();
        $old = $this->legacyKey($app);

        $html = $this->actingAs($this->admin())->get(route('apps.show', $app))->assertOk()
            ->assertSee('Issued before keys were viewable')
            ->assertDontSee('API token')
            ->getContent();
        $this->assertStringContainsString('Revoke key '.$old->prefix.'?', html_entity_decode($html));
        $this->assertStringNotContainsString($this->inJs(route('apps.reveal', [$app, 'token:'.$old->id])), $html);

        $res = $this->reveal($app, 'token:'.$old->id)->assertStatus(422)->assertJson(['message' => 'Issued before keys were viewable.']);
        $this->assertArrayNotHasKey('value', $res->json());
        $this->assertSame(0, AuditEntry::query()->where('action', 'api_key.viewed')->count());

        $this->bearerGet('/api/v1/ping', 'vsk_'.str_repeat('L', 40))->assertOk();
        $this->actingAs($this->admin())->post(route('apps.keys.revoke', [$app, $old->id]))->assertSessionHas('status', 'Revoked');
        $this->bearerGet('/api/v1/ping', 'vsk_'.str_repeat('L', 40))->assertUnauthorized();
    }

    public function test_revoke_then_reveal_is_refused(): void
    {
        $app = ClientApp::factory()->create();
        $issued = app(PlatformApps::class)->issueKey($app, 'K');
        $key = $issued['key'];
        $this->bearerGet('/api/v1/ping', $issued['plaintext_key'])->assertOk();

        $this->actingAs($this->admin())->post(route('apps.keys.revoke', [$app, $key->id]))
            ->assertRedirect(route('apps.show', $app).'#keys')->assertSessionHas('status', 'Revoked');
        $this->assertNull($key->fresh());

        $res = $this->reveal($app, 'token:'.$key->id)->assertNotFound();
        $this->assertArrayNotHasKey('value', $res->json() ?? []);
        $this->bearerGet('/api/v1/ping', $issued['plaintext_key'])->assertUnauthorized()->assertJsonPath('code', 'unauthorized');

        $this->actingAs($this->admin())->post(route('apps.keys.revoke', [$app, $key->id]))->assertNotFound();
        $revoked = AuditEntry::query()->where('action', 'api_key.revoked')->sole();
        $this->assertSame(['name' => 'K', 'key_prefix' => $key->prefix], $revoked->changes);
    }

    public function test_other_apps_tokens_are_not_found(): void
    {
        $app = ClientApp::factory()->create();
        $other = ClientApp::factory()->create();
        $theirs = app(PlatformApps::class)->issueKey($other, 'Theirs')['key'];

        $this->reveal($app, 'token:'.$theirs->id)->assertNotFound();
        $this->reveal($app, 'token:999999')->assertNotFound();
        $this->actingAs($this->admin())->post('/platforms/'.$app->public_id.'/reveal/token:'.$theirs->prefix)->assertNotFound();
        $this->actingAs($this->admin())->post('/platforms/'.$app->public_id.'/reveal/key:'.$theirs->id)->assertNotFound();
        $this->actingAs($this->admin())->post('/platforms/'.$app->public_id.'/reveal/secret:'.$theirs->id)->assertNotFound();
        $this->actingAs($this->admin())->post(route('apps.keys.revoke', [$app, $theirs->id]))->assertNotFound();
        $this->assertNotNull($theirs->fresh());
        $this->assertSame(0, AuditEntry::query()->where('action', 'api_key.viewed')->count());
    }

    public function test_tokens_sharing_a_prefix_each_reveal_their_own(): void
    {
        $app = ClientApp::factory()->create();
        $a = app(PlatformApps::class)->issueKey($app, 'A');
        $b = app(PlatformApps::class)->issueKey($app, 'B');
        DB::table('personal_access_tokens')->where('id', $b['key']->id)->update(['prefix' => $a['key']->prefix]);

        $this->assertSame($a['plaintext_key'], $this->reveal($app, 'token:'.$a['key']->id)->assertOk()->json('value'));
        $this->assertSame($b['plaintext_key'], $this->reveal($app, 'token:'.$b['key']->id)->assertOk()->json('value'));

        $html = $this->actingAs($this->admin())->get(route('apps.show', $app))->getContent();
        $this->assertStringContainsString($this->inJs(route('apps.reveal', [$app, 'token:'.$a['key']->id])), $html);
        $this->assertStringContainsString($this->inJs(route('apps.reveal', [$app, 'token:'.$b['key']->id])), $html);
    }

    public function test_unset_webhook_secret_can_be_regenerated(): void
    {
        $app = ClientApp::factory()->create();
        DB::table('client_apps')->where('id', $app->id)->update(['webhook_secret' => null]);

        $html = $this->actingAs($this->admin())->get(route('apps.show', $app))->assertOk()->assertSee('Not set')->getContent();
        $this->assertStringContainsString('Regenerate the webhook secret?', html_entity_decode($html));
        $this->assertStringContainsString('id="regenerate-webhook"', $html);

        $this->actingAs($this->admin())->post(route('apps.webhook.regenerate', $app))->assertSessionHas('status', 'Regenerated');
        $this->reveal($app, 'webhook')->assertOk();
    }

    public function test_unknown_what_is_not_found(): void
    {
        $app = ClientApp::factory()->create();
        $this->actingAs($this->admin())->post(route('apps.reveal', [$app, 'nope']))->assertNotFound();
        $this->actingAs($this->admin())->post('/platforms/'.$app->public_id.'/reveal/token:')->assertNotFound();
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
