<?php

namespace Tests\Feature\Ui;

use App\Models\Admin;
use App\Models\ApiToken;
use App\Models\AuditEntry;
use App\Models\ClientApp;
use App\Platforms\PlatformApps;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ApiKeysTest extends TestCase
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

    private function bearerGet(string $uri, string $token): TestResponse
    {
        return $this->call('GET', $uri, [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
    }

    private function reveal(ClientApp $app, string $what): TestResponse
    {
        return $this->actingAs($this->admin())->postJson(route('developers.reveal', [$app, $what]));
    }

    /** A string as @js() writes it into an attribute. */
    private function inJs(string $value): string
    {
        return str_replace('/', '\\\\\\/', $value);
    }

    private function secretPart(string $plain): string
    {
        return explode('|', $plain, 2)[1];
    }

    private function legacyKey(ClientApp $app): ApiToken
    {
        $plain = 'vsk_'.str_repeat('L', 40);

        return $app->tokens()->create([
            'name' => 'Old', 'prefix' => substr($plain, 0, 8), 'token' => hash('sha256', $plain), 'abilities' => ['*'],
        ]);
    }

    public function test_empty_state_offers_create_key(): void
    {
        $this->page()->assertSee('No API keys')->assertSee('Create key')
            ->assertSee(route('developers.index', ['tab' => 'keys', 'create' => 1]));
    }

    public function test_create_form_is_inline_and_server_rendered_without_js(): void
    {
        $closed = $this->page()->getContent();
        $this->assertMatchesRegularExpression('/x-data="\{ creating: false \}"/', $closed);

        $open = $this->page(['create' => 1])->assertSee('action="'.route('developers.keys.store').'"', false)->getContent();
        $this->assertMatchesRegularExpression('/x-data="\{ creating: true \}"/', $open);
        $this->assertStringContainsString('name="name"', $open);
        $this->assertStringContainsString('maxlength="255"', $open);
    }

    public function test_create_key_makes_an_app_and_reveals_the_new_key_once(): void
    {
        $res = $this->actingAs($this->admin())->post(route('developers.keys.store'), ['name' => 'VentaCart Pro']);
        $sessionAfter = serialize(session()->all());

        $app = ClientApp::query()->sole();
        $key = ApiToken::query()->sole();
        $this->assertSame('VentaCart Pro', $app->name);
        $this->assertNull($app->webhook_url);
        $this->assertTrue($key->tokenable->is($app));
        $res->assertRedirect(route('developers.index', ['tab' => 'keys']))
            ->assertSessionHas('issued_key', $key->id)->assertSessionHas('status', 'Created');

        $plain = $this->reveal($app, 'token:'.$key->id)->assertOk()->assertHeader('Pragma', 'no-cache')->json('value');
        $this->assertStringNotContainsString($this->secretPart($plain), $sessionAfter);

        $html = $this->withSession(['issued_key' => $key->id])->get(route('developers.index'))->assertOk()
            ->assertSee('VentaCart Pro')->getContent();
        $this->assertStringNotContainsString($this->secretPart($plain), $html);
        $this->assertStringContainsString('\u0022autoView\u0022:true', $html);
        $this->assertStringContainsString($this->inJs(route('developers.reveal', [$app, 'token:'.$key->id])), $html);

        $this->bearerGet('/api/v1/ping', $plain)->assertOk()->assertJsonPath('app.id', $app->public_id);
        $this->assertSame(1, AuditEntry::query()->where('action', 'client_app.created')->count());
        $this->assertSame(1, AuditEntry::query()->where('action', 'api_key.issued')->count());
        $this->assertStringNotContainsString($this->secretPart($plain), json_encode(DB::table('audit_entries')->get()->all()));
    }

    public function test_create_validation_reopens_the_form(): void
    {
        $this->actingAs($this->admin());
        $this->from(route('developers.index'))->post(route('developers.keys.store'), ['name' => ''])
            ->assertRedirect(route('developers.index', ['tab' => 'keys', 'create' => 1]))->assertSessionHasErrors('name');
        $this->post(route('developers.keys.store'), ['name' => str_repeat('a', 256)])->assertSessionHasErrors('name');
        $this->assertSame(0, ClientApp::count());
        $this->assertSame(0, ApiToken::count());
    }

    public function test_list_shows_masked_keys_and_never_plaintext(): void
    {
        $this->travelTo('2026-11-09 12:00:00');
        $app = ClientApp::factory()->create(['name' => 'Shop', 'webhook_url' => 'https://shop.test/hook']);
        $issued = app(PlatformApps::class)->issueKey($app, 'Shop');
        $issued['key']->forceFill(['last_used_at' => now()->subMinutes(2)])->save();
        $webhookSecret = app(PlatformApps::class)->revealWebhookSecret($app, self::ACTOR);

        $html = $this->page()->assertSeeInOrder(['Name', 'Key', 'Last used', 'Created', 'Shop', substr($issued['plaintext_key'], -4), '2 minutes ago', '9 Nov 2026'])
            ->getContent();
        $this->assertStringNotContainsString($this->secretPart($issued['plaintext_key']), $html);
        $this->assertStringNotContainsString($webhookSecret, $html);
        $this->assertStringNotContainsString('autoView\u0022:true', $html);
        $this->assertStringContainsString('data-label="Last used"', $html);
        $this->assertStringContainsString('data-label="Created"', $html);
        $this->assertStringContainsString('Roll Shop? The current key stops working.', html_entity_decode($html));
        $this->assertStringContainsString('Revoke Shop? Apps using it stop working.', html_entity_decode($html));
    }

    public function test_disabled_apps_expired_keys_and_other_rows(): void
    {
        $off = ClientApp::factory()->create(['name' => 'Gone Shop', 'status' => 'disabled']);
        app(PlatformApps::class)->issueKey($off, 'K');
        $app = ClientApp::factory()->create(['name' => 'Live Shop']);
        app(PlatformApps::class)->issueKey($app, 'K');
        app(PlatformApps::class)->issueKey($app, 'Lapsed')['key']->update(['expires_at' => now()->subMinute()]);
        ClientApp::factory()->create(['name' => 'Keyless Shop']);

        $this->page()->assertSee('Live Shop')->assertDontSee('Gone Shop')->assertDontSee('Lapsed')
            ->assertSeeInOrder(['Keyless Shop', 'No key', 'Live Shop']);
    }

    public function test_apps_with_several_keys_list_each_key(): void
    {
        $app = ClientApp::factory()->create(['name' => 'Multi']);
        $a = app(PlatformApps::class)->issueKey($app, 'Storefront')['key'];
        $b = app(PlatformApps::class)->issueKey($app, 'Backoffice')['key'];

        $html = $this->page()->assertSeeInOrder(['Multi · Storefront', 'Multi · Backoffice'])->getContent();
        $this->assertStringContainsString($this->inJs(route('developers.reveal', [$app, 'token:'.$a->id])), $html);
        $this->assertStringContainsString($this->inJs(route('developers.reveal', [$app, 'token:'.$b->id])), $html);
    }

    public function test_roll_issues_a_new_key_and_revokes_the_old(): void
    {
        $app = ClientApp::factory()->create(['name' => 'Shop']);
        $old = app(PlatformApps::class)->issueKey($app, 'Shop');

        $this->actingAs($this->admin())->post(route('developers.keys.roll', $app), ['key' => $old['key']->id])
            ->assertRedirect(route('developers.index', ['tab' => 'keys']))->assertSessionHas('status', 'Rolled');

        $new = ApiToken::query()->sole();
        $this->assertNotSame($old['key']->id, $new->id);
        $this->assertSame('Shop', $new->name);
        $this->assertSame(session('issued_key'), $new->id);
        $this->bearerGet('/api/v1/ping', $old['plaintext_key'])->assertUnauthorized();
        $this->bearerGet('/api/v1/ping', $this->reveal($app, 'token:'.$new->id)->json('value'))->assertOk();
        $this->assertSame('active', $app->fresh()->status);
    }

    public function test_roll_on_a_keyless_app_issues_its_first_key(): void
    {
        $app = ClientApp::factory()->create(['name' => 'Keyless']);
        $this->actingAs($this->admin())->post(route('developers.keys.roll', $app))->assertSessionHas('status', 'Rolled');
        $this->assertSame(1, $app->tokens()->count());
    }

    public function test_roll_or_revoke_of_another_apps_key_is_404(): void
    {
        $app = ClientApp::factory()->create();
        $theirs = app(PlatformApps::class)->issueKey(ClientApp::factory()->create(), 'T')['key'];
        $this->actingAs($this->admin());

        $this->post(route('developers.keys.roll', $app), ['key' => $theirs->id])->assertNotFound();
        $this->post(route('developers.keys.revoke', $app), ['key' => $theirs->id])->assertNotFound();
        $this->put(route('developers.keys.rename', $app), ['name' => 'X', 'key' => $theirs->id])->assertNotFound();
        $this->assertNotNull($theirs->fresh());
        $this->assertSame(1, ApiToken::count());
    }

    public function test_revoke_removes_the_key_and_disables_the_app(): void
    {
        $app = ClientApp::factory()->create(['name' => 'Shop']);
        $issued = app(PlatformApps::class)->issueKey($app, 'K');

        $this->actingAs($this->admin())->post(route('developers.keys.revoke', $app), ['key' => $issued['key']->id])
            ->assertRedirect(route('developers.index', ['tab' => 'keys']))->assertSessionHas('status', 'Revoked');

        $this->assertNull($issued['key']->fresh());
        $this->assertSame('disabled', $app->fresh()->status);
        $this->bearerGet('/api/v1/ping', $issued['plaintext_key'])->assertUnauthorized();
        $this->reveal($app, 'token:'.$issued['key']->id)->assertNotFound();
        $this->page()->assertSee('No API keys');
    }

    public function test_revoking_one_of_several_keys_keeps_the_app(): void
    {
        $app = ClientApp::factory()->create(['name' => 'Multi']);
        $a = app(PlatformApps::class)->issueKey($app, 'A')['key'];
        app(PlatformApps::class)->issueKey($app, 'B');

        $this->actingAs($this->admin())->post(route('developers.keys.revoke', $app), ['key' => $a->id]);
        $this->assertSame('active', $app->fresh()->status);
        $this->page()->assertSee('Multi')->assertDontSee('Multi · B');
    }

    public function test_revoke_a_keyless_app_disables_it(): void
    {
        $app = ClientApp::factory()->create(['name' => 'Keyless']);
        $this->actingAs($this->admin())->post(route('developers.keys.revoke', $app))->assertSessionHas('status', 'Revoked');
        $this->assertSame('disabled', $app->fresh()->status);
    }

    public function test_rename_renames_the_app_or_one_of_several_keys(): void
    {
        $app = ClientApp::factory()->create(['name' => 'Shop']);
        $key = app(PlatformApps::class)->issueKey($app, 'Shop')['key'];
        $this->actingAs($this->admin());

        $this->put(route('developers.keys.rename', $app), ['name' => 'VentaCart Pro', 'key' => $key->id])
            ->assertRedirect(route('developers.index', ['tab' => 'keys']))->assertSessionHas('status', 'Renamed');
        $this->assertSame('VentaCart Pro', $app->fresh()->name);

        $other = app(PlatformApps::class)->issueKey($app, 'Second')['key'];
        $this->put(route('developers.keys.rename', $app), ['name' => 'Backoffice', 'key' => $other->id]);
        $this->assertSame('VentaCart Pro', $app->fresh()->name);
        $this->assertSame('Backoffice', $other->fresh()->name);
        $this->assertSame(1, AuditEntry::query()->where('action', 'api_key.renamed')->count());

        $this->from(route('developers.index'))->put(route('developers.keys.rename', $app), ['name' => '', 'key' => $key->id])->assertSessionHasErrors('rename');
    }

    public function test_rename_form_is_server_rendered_from_the_query(): void
    {
        $app = ClientApp::factory()->create(['name' => 'Shop']);
        $key = app(PlatformApps::class)->issueKey($app, 'Shop')['key'];

        $html = $this->page(['rename' => 'key-'.$key->id])->getContent();
        $this->assertStringContainsString('x-data="{ renaming: true }"', $html);
        $this->assertStringContainsString('action="'.route('developers.keys.rename', $app).'"', $html);
        $this->assertStringContainsString('value="Shop"', $html);
    }

    public function test_legacy_key_is_listed_without_view(): void
    {
        $app = ClientApp::factory()->create(['name' => 'Old shop']);
        $old = $this->legacyKey($app);

        $html = $this->page()->assertSee('Old shop')->assertSee($old->prefix)->getContent();
        $this->assertStringNotContainsString($this->inJs(route('developers.reveal', [$app, 'token:'.$old->id])), $html);
        $this->reveal($app, 'token:'.$old->id)->assertStatus(422)->assertJson(['message' => 'Issued before keys were viewable.']);
    }

    public function test_reveal_refuses_unknown_targets(): void
    {
        $app = ClientApp::factory()->create();
        $theirs = app(PlatformApps::class)->issueKey(ClientApp::factory()->create(), 'T')['key'];
        $this->reveal($app, 'token:'.$theirs->id)->assertNotFound();
        $this->reveal($app, 'token:999999')->assertNotFound();
        $this->actingAs($this->admin())->post('/developers/apps/'.$app->public_id.'/reveal/nope')->assertNotFound();
        $this->assertSame(0, AuditEntry::query()->where('action', 'api_key.viewed')->count());
    }

    public function test_reveal_is_throttled(): void
    {
        $app = ClientApp::factory()->create();
        for ($i = 0; $i < 10; $i++) {
            $this->reveal($app, 'webhook')->assertOk();
        }
        $this->reveal($app, 'webhook')->assertStatus(429);
    }
}
