<?php

namespace Tests\Feature\Api;

use App\Models\ApiRequest;
use App\Models\RequestSignature;
use Tests\Concerns\SignsApiRequests;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase, SignsApiRequests;

    private function assertUnauthorized($response, string $reason): void
    {
        $response->assertStatus(401);
        $this->assertSame(
            '{"error":"Unauthorized","code":"unauthorized","retryable":false,"errors":{}}',
            $response->getContent(),
        );
        $this->assertSame($reason, ApiRequest::query()->latest('id')->value('error'));
    }

    public function test_logged_request_time_is_set_by_the_app(): void
    {
        $this->travelTo('2026-11-09 12:00:00');
        $this->signedJson('GET', '/api/v1/ping');

        $this->assertSame('2026-11-09 12:00:00', ApiRequest::query()->latest('id')->first()->created_at);
    }

    public function test_valid_signature_returns_app(): void
    {
        $client = $this->makeClient();

        $this->signedJson('GET', '/api/v1/ping', client: $client)
            ->assertOk()
            ->assertExactJson(['app' => ['id' => $client['app']->public_id, 'name' => 'VentaCart Pro']]);

        $this->assertStringStartsWith('app_', $client['app']->public_id);
        $this->assertNotNull($client['app']->apiKeys()->first()->last_used_at);
        $this->assertSame(1, RequestSignature::count());
    }

    public function test_missing_bearer_is_401_unauthorized(): void
    {
        $response = $this->getJson('/api/v1/ping');

        $this->assertUnauthorized($response, 'missing_bearer');
        $this->assertSame('unauthorized', $response->json('code'));
        $this->assertSame('Unauthorized', $response->json('error'));
    }

    public function test_unknown_key_is_401(): void
    {
        $client = $this->makeClient();
        $client['key'] = 'vsk_' . str_repeat('a', 40);

        $this->assertUnauthorized($this->signedJson('GET', '/api/v1/ping', client: $client), 'unknown_key');
    }

    public function test_wrong_signature_is_401(): void
    {
        $response = $this->signedJson('GET', '/api/v1/ping', headers: ['X-Signature' => 'sha256=' . str_repeat('0', 64)]);

        $this->assertUnauthorized($response, 'bad_signature');
        $this->assertSame('bad_signature', ApiRequest::query()->first()->error);
    }

    public function test_stale_timestamp_is_401(): void
    {
        $response = $this->signedJson('GET', '/api/v1/ping', headers: ['X-Timestamp' => (string) (time() - 301)]);

        $this->assertUnauthorized($response, 'stale_timestamp');
    }

    public function test_replayed_signature_is_401(): void
    {
        $client = $this->makeClient();
        $headers = ['X-Timestamp' => (string) time()];

        $this->signedJson('GET', '/api/v1/ping', headers: $headers, client: $client)->assertOk();
        $second = $this->signedJson('GET', '/api/v1/ping', headers: $headers, client: $client);

        $this->assertUnauthorized($second, 'replay');
        $this->assertSame(1, RequestSignature::count());
    }

    public function test_revoked_key_is_401(): void
    {
        $client = $this->makeClient();
        $client['app']->apiKeys()->update(['revoked_at' => now()]);

        $this->assertUnauthorized($this->signedJson('GET', '/api/v1/ping', client: $client), 'revoked');
    }

    public function test_disabled_app_is_403_app_disabled(): void
    {
        $client = $this->makeClient();
        $client['app']->update(['status' => 'disabled']);

        $this->signedJson('GET', '/api/v1/ping', client: $client)
            ->assertStatus(403)
            ->assertJson(['code' => 'app_disabled', 'retryable' => false]);
        $this->assertSame('app_disabled', ApiRequest::query()->first()->error);
    }

    private function sendEmptyBody(array $client, string $signedTarget, string $sentTarget)
    {
        $ts = time();
        $headers = [
            'Authorization' => 'Bearer ' . $client['key'],
            'X-Timestamp' => (string) $ts,
            'X-Signature' => \App\Api\Signature::sign($client['secret'], $ts, 'GET', $signedTarget, ''),
            'Accept' => 'application/json',
        ];

        return $this->call('GET', $sentTarget, [], [], [], $this->transformHeadersToServerVars($headers), '');
    }

    public function test_query_string_is_part_of_target(): void
    {
        $response = $this->sendEmptyBody($this->makeClient(), '/api/v1/ping?x=1', '/api/v1/ping?x=2');

        $this->assertUnauthorized($response, 'bad_signature');
    }

    public function test_signed_query_string_is_accepted(): void
    {
        $this->sendEmptyBody($this->makeClient(), '/api/v1/ping?x=1', '/api/v1/ping?x=1')->assertOk();
    }

    public function test_every_request_is_logged(): void
    {
        $client = $this->makeClient();
        $this->signedJson('GET', '/api/v1/ping', client: $client)->assertOk();

        $row = ApiRequest::query()->sole();
        $this->assertSame('GET', $row->method);
        $this->assertSame('/api/v1/ping', $row->path);
        $this->assertSame(200, $row->status);
        $this->assertNotNull($row->duration_ms);
        $this->assertSame($client['app']->getKey(), $row->client_app_id);
        $this->assertNull($row->error);
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
