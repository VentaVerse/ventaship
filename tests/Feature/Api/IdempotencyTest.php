<?php

namespace Tests\Feature\Api;

use App\Api\ApiError;
use App\Models\IdempotencyKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\CallsClientApi;
use Tests\TestCase;

class IdempotencyTest extends TestCase
{
    use RefreshDatabase, CallsClientApi;

    private int $calls = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calls = 0;

        Route::middleware(['api', 'client.auth', 'idempotent'])->prefix('api/v1')->group(function () {
            Route::post('_test/echo', function (Request $request) {
                $this->calls++;
                $mode = $request->input('mode');
                if ($mode === 'boom') {
                    return response()->json(['error' => 'boom'], 500);
                }
                if ($mode === 'busy') {
                    throw new ApiError(429, 'rate_limited', 'Slow down', true);
                }
                if ($mode === 'refuse') {
                    throw new ApiError(422, 'nope', 'Refused');
                }

                return response()->json(['n' => $this->calls, 'data' => $request->input('data')], 201);
            });
        });
    }

    private function send(array $body, ?string $key = 'k1', ?array $client = null)
    {
        return $this->apiJson('POST', '/api/v1/_test/echo', $body, array_filter(['Idempotency-Key' => $key]), $client);
    }

    public function test_same_key_same_body_replays_with_header(): void
    {
        $client = $this->makeClient();
        $first = $this->send(['data' => 'a'], client: $client);
        $second = $this->send(['data' => 'a'], client: $client);

        $first->assertStatus(201)->assertHeaderMissing('Idempotent-Replayed');
        $second->assertStatus(201)->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame($first->getContent(), $second->getContent());
        $this->assertSame(1, $this->calls);
    }

    public function test_same_key_different_body_is_422(): void
    {
        $client = $this->makeClient();
        $this->send(['data' => 'a'], client: $client)->assertStatus(201);

        $this->send(['data' => 'b'], client: $client)
            ->assertStatus(422)
            ->assertJson(['code' => 'idempotency_key_reused', 'error' => 'Idempotency-Key was already used with a different request']);
        $this->assertSame(1, $this->calls);
    }

    public function test_in_flight_duplicate_is_409_idempotency_in_progress(): void
    {
        $client = $this->makeClient();
        $body = ['data' => 'a'];
        $json = json_encode($body);
        IdempotencyKey::query()->create([
            'client_app_id' => $client['app']->getKey(),
            'key' => 'k1',
            'request_hash' => hash('sha256', "POST /api/v1/_test/echo\n".$json),
            'status' => 'processing',
        ]);

        $this->send($body, client: $client)
            ->assertStatus(409)
            ->assertJson(['code' => 'idempotency_in_progress', 'retryable' => true]);
        $this->assertSame(0, $this->calls);
    }

    public function test_5xx_is_not_stored(): void
    {
        $client = $this->makeClient();
        $this->send(['mode' => 'boom'], client: $client)->assertStatus(500);
        $this->assertSame(0, IdempotencyKey::count());

        $this->send(['mode' => 'boom'], client: $client)->assertStatus(500);
        $this->assertSame(2, $this->calls);
    }

    public function test_retryable_refusal_is_not_stored_but_final_refusal_is_replayed(): void
    {
        $client = $this->makeClient();
        $this->send(['mode' => 'busy'], client: $client)->assertStatus(429)->assertJson(['retryable' => true]);
        $this->assertSame(0, IdempotencyKey::count());

        $this->send(['mode' => 'refuse'], 'k2', $client)->assertStatus(422)->assertJson(['code' => 'nope']);
        $this->send(['mode' => 'refuse'], 'k2', $client)
            ->assertStatus(422)->assertJson(['code' => 'nope'])->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame(2, $this->calls);
    }

    public function test_keys_are_scoped_per_app(): void
    {
        $a = $this->makeClient();
        $b = $this->makeClient();

        $this->send(['data' => 'a'], client: $a)->assertStatus(201)->assertHeaderMissing('Idempotent-Replayed');
        $this->send(['data' => 'a'], client: $b)->assertStatus(201)->assertHeaderMissing('Idempotent-Replayed');
        $this->assertSame(2, $this->calls);
        $this->assertSame(2, IdempotencyKey::count());
    }

    public function test_missing_or_oversized_key_is_422_invalid_request(): void
    {
        $client = $this->makeClient();

        $this->send(['data' => 'a'], null, $client)
            ->assertStatus(422)
            ->assertJson(['code' => 'invalid_request'])
            ->assertJsonStructure(['errors' => ['Idempotency-Key']]);
        $this->send(['data' => 'c'], str_repeat('x', 101), $client)->assertStatus(422)->assertJson(['code' => 'invalid_request']);
        $this->assertSame(0, $this->calls);
    }
}
