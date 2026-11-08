<?php

namespace Tests\Feature\Api;

use App\Models\Admin;
use App\Models\ApiRequest;
use App\Models\ApiToken;
use App\Platforms\PlatformApps;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CallsClientApi;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use CallsClientApi, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function assertUnauthorized($response): void
    {
        $response->assertStatus(401);
        $this->assertSame(
            '{"error":"Unauthorized","code":"unauthorized","retryable":false,"errors":{}}',
            $response->getContent(),
        );
        $this->assertSame('unauthorized', ApiRequest::query()->latest('id')->value('error'));
    }

    /** Only the Authorization header: no Accept, no signature headers. */
    private function bearerOnly(string $token, string $uri = '/api/v1/ping')
    {
        return $this->call('GET', $uri, [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
    }

    public function test_logged_request_time_is_set_by_the_app(): void
    {
        $this->travelTo('2026-11-09 12:00:00');
        $this->apiJson('GET', '/api/v1/ping');

        $this->assertSame('2026-11-09 12:00:00', ApiRequest::query()->latest('id')->first()->created_at);
    }

    public function test_bearer_token_alone_returns_app(): void
    {
        $client = $this->makeClient();

        $this->bearerOnly($client['key'])
            ->assertOk()
            ->assertExactJson(['app' => ['id' => $client['app']->public_id, 'name' => 'VentaCart Pro']]);

        $this->assertStringStartsWith('app_', $client['app']->public_id);
        $this->assertMatchesRegularExpression('/^\d+\|vsk_[A-Za-z0-9]{48}$/', $client['key']);
        $this->assertNotNull($client['app']->tokens()->first()->last_used_at);
    }

    public function test_revealed_token_authenticates(): void
    {
        $app = \App\Models\ClientApp::factory()->create();
        $key = app(PlatformApps::class)->issueKey($app, 'K')['key'];
        $admin = Admin::query()->create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'correct-horse-battery']);

        $token = $this->actingAs($admin)->postJson(route('apps.reveal', [$app, 'token:'.$key->id]))->assertOk()->json('value');

        $this->bearerOnly($token)->assertOk()->assertJsonPath('app.id', $app->public_id);
    }

    public function test_missing_bearer_is_401_unauthorized(): void
    {
        $this->assertUnauthorized($this->getJson('/api/v1/ping'));
    }

    public function test_missing_bearer_without_accept_header_is_still_json_401_not_a_redirect(): void
    {
        $response = $this->call('GET', '/api/v1/ping');

        $this->assertUnauthorized($response);
        $this->assertSame('application/json', $response->headers->get('Content-Type'));
    }

    public function test_unknown_token_is_401(): void
    {
        $client = $this->makeClient();

        $this->assertUnauthorized($this->bearerOnly('vsk_'.str_repeat('a', 48)));
        $this->assertUnauthorized($this->bearerOnly(explode('|', $client['key'])[0].'|vsk_'.str_repeat('a', 48)));
    }

    public function test_token_of_another_id_is_401(): void
    {
        $first = $this->makeClient();
        $second = $this->makeClient();
        [, $secret] = explode('|', $first['key'], 2);
        [$otherId] = explode('|', $second['key'], 2);

        $this->assertUnauthorized($this->bearerOnly($otherId.'|'.$secret));
    }

    public function test_revoked_token_is_401(): void
    {
        $client = $this->makeClient();
        app(PlatformApps::class)->revokeKey($client['app']->tokens()->sole());

        $this->assertUnauthorized($this->bearerOnly($client['key']));
        $this->assertSame(0, ApiToken::query()->count());
    }

    public function test_expired_token_is_401(): void
    {
        $client = $this->makeClient();
        $client['app']->tokens()->update(['expires_at' => now()->subMinute()]);

        $this->assertUnauthorized($this->bearerOnly($client['key']));
    }

    public function test_token_of_another_model_is_401(): void
    {
        $admin = Admin::query()->create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'correct-horse-battery']);
        $plain = 'vsk_'.str_repeat('b', 48);
        $token = new ApiToken(['name' => 'Odd', 'token' => hash('sha256', $plain), 'abilities' => ['*']]);
        $token->tokenable()->associate($admin)->save();

        $this->assertUnauthorized($this->bearerOnly($plain));
    }

    public function test_admin_session_does_not_authenticate_the_api(): void
    {
        $admin = Admin::query()->create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'correct-horse-battery']);

        $this->assertUnauthorized($this->actingAs($admin)->getJson('/api/v1/ping'));
    }

    public function test_sanctum_cookie_route_is_not_registered(): void
    {
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('sanctum.csrf-cookie'));
        $this->get('/sanctum/csrf-cookie')->assertNotFound();
    }

    public function test_disabled_app_is_403_app_disabled(): void
    {
        $client = $this->makeClient();
        $client['app']->update(['status' => 'disabled']);

        $this->bearerOnly($client['key'])
            ->assertStatus(403)
            ->assertJson(['code' => 'app_disabled', 'retryable' => false]);
        $this->assertSame('app_disabled', ApiRequest::query()->first()->error);
    }

    public function test_signature_headers_are_ignored(): void
    {
        $client = $this->makeClient();

        $this->apiJson('GET', '/api/v1/ping', headers: ['X-Timestamp' => '1', 'X-Signature' => 'sha256=nope'], client: $client)->assertOk();
    }

    public function test_every_request_is_logged_with_its_token(): void
    {
        $client = $this->makeClient();
        $this->apiJson('GET', '/api/v1/ping', client: $client)->assertOk();

        $row = ApiRequest::query()->sole();
        $this->assertSame('GET', $row->method);
        $this->assertSame('/api/v1/ping', $row->path);
        $this->assertSame(200, $row->status);
        $this->assertNotNull($row->duration_ms);
        $this->assertSame($client['app']->getKey(), $row->client_app_id);
        $token = $client['app']->tokens()->sole();
        $this->assertSame($token->getKey(), $row->token_id);
        $this->assertSame($token->prefix, $row->token_prefix);
        $this->assertSame('Test', $row->token_name);
        $this->assertNull($row->error);

        // Revoking deletes the token; the history still names it.
        app(PlatformApps::class)->revokeKey($token);
        $row->refresh();
        $this->assertNull($row->token_id);
        $this->assertSame($token->prefix, $row->token_prefix);
        $this->assertSame('Test', $row->token_name);
    }

    public function test_validation_exception_on_api_is_422_invalid_request(): void
    {
        \Illuminate\Support\Facades\Route::middleware('api')->post('api/v1/_probe', function (\Illuminate\Http\Request $r) {
            $r->validate(['name' => 'required']);
        });

        $this->postJson('/api/v1/_probe', [])
            ->assertStatus(422)
            ->assertJson(['code' => 'invalid_request', 'retryable' => false, 'errors' => ['name' => ['The name field is required.']]]);
    }
}
