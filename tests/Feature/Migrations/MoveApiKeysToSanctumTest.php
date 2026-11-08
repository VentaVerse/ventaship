<?php

namespace Tests\Feature\Migrations;

use App\Models\ApiRequest;
use App\Models\ApiToken;
use App\Models\ClientApp;
use App\Support\Vault\CredentialVault;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** The migration that moves pre-Sanctum api_keys rows into personal_access_tokens. */
class MoveApiKeysToSanctumTest extends TestCase
{
    use DatabaseMigrations;

    private const ACTIVE = 'vsk_'.'Ab3dEf6hIj9kLm2nOp5qRs8tUv1wXy4zAb7cDe0f';

    private const LEGACY = 'vsk_'.'Zz9yXx8wVv7uTt6sRr5qPp4oOo3nNn2mMm1lLl0k';

    private const REVOKED = 'vsk_'.'Rv0kEdRv0kEdRv0kEdRv0kEdRv0kEdRv0kEdRv0k';

    private object $migration;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->migration = require database_path('migrations/2026_11_09_000300_move_api_keys_to_sanctum_tokens.php');
        $this->migration->down();
    }

    /** @return array{app: ClientApp, active: int, legacy: int, revoked: int} */
    private function seedKeys(): array
    {
        $app = ClientApp::factory()->create();
        $vault = app(CredentialVault::class);
        $row = fn (string $plain, string $name, array $extra = []) => DB::table('api_keys')->insertGetId($extra + [
            'client_app_id' => $app->id,
            'name' => $name,
            'key_prefix' => substr($plain, 0, 8),
            'key_hash' => hash('sha256', $plain),
            'key_encrypted' => null,
            'signing_secret' => $vault->encrypt(str_repeat('S', 48)),
            'last_used_at' => null,
            'created_at' => '2026-11-01 10:00:00',
            'updated_at' => '2026-11-01 10:00:00',
        ]);

        $active = $row(self::ACTIVE, 'Production', [
            'key_encrypted' => $vault->encrypt(self::ACTIVE), 'last_used_at' => '2026-11-08 09:30:00',
        ]);
        $legacy = $row(self::LEGACY, 'Old');
        $revoked = $row(self::REVOKED, 'Gone', ['revoked_at' => '2026-11-05 00:00:00']);

        DB::table('api_requests')->insert([
            ['client_app_id' => $app->id, 'api_key_id' => $active, 'method' => 'GET', 'path' => '/api/v1/ping', 'status' => 200, 'created_at' => now()],
            ['client_app_id' => $app->id, 'api_key_id' => $revoked, 'method' => 'GET', 'path' => '/api/v1/ping', 'status' => 401, 'created_at' => now()],
        ]);

        return ['app' => $app, 'active' => $active, 'legacy' => $legacy, 'revoked' => $revoked];
    }

    private function ping(string $token)
    {
        return $this->getJson('/api/v1/ping', ['Authorization' => 'Bearer '.$token]);
    }

    public function test_active_keys_move_with_hash_name_dates_and_encrypted_copy(): void
    {
        $seeded = $this->seedKeys();

        $this->migration->up();

        $this->assertFalse(Schema::hasTable('api_keys'));
        $this->assertFalse(Schema::hasTable('request_signatures'));
        $this->assertFalse(Schema::hasColumn('api_requests', 'api_key_id'));

        $tokens = ApiToken::query()->orderBy('id')->get();
        $this->assertSame([$seeded['active'], $seeded['legacy']], $tokens->modelKeys());

        $active = $tokens[0];
        $this->assertTrue($active->tokenable->is($seeded['app']));
        $this->assertSame('Production', $active->name);
        $this->assertSame(hash('sha256', self::ACTIVE), $active->token);
        $this->assertSame('vsk_Ab3d', $active->prefix);
        $this->assertSame(self::ACTIVE, $active->token_encrypted);
        $this->assertSame('2026-11-08 09:30:00', $active->last_used_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-01 10:00:00', $active->created_at->format('Y-m-d H:i:s'));
        $this->assertSame(['*'], $active->abilities);
        $this->assertNull($tokens[1]->getRawOriginal('token_encrypted'));

        $this->assertSame(
            [$seeded['active'], null],
            ApiRequest::query()->orderBy('id')->pluck('token_id')->all(),
        );
    }

    public function test_moved_keys_authenticate_and_revoked_ones_do_not(): void
    {
        $seeded = $this->seedKeys();

        $this->migration->up();

        $this->ping(self::ACTIVE)->assertOk()->assertJsonPath('app.id', $seeded['app']->public_id);
        $this->ping(self::LEGACY)->assertOk();
        $this->ping($seeded['active'].'|'.self::ACTIVE)->assertOk();
        $this->ping(self::REVOKED)->assertStatus(401)->assertJsonPath('code', 'unauthorized');
    }

    public function test_rerun_is_harmless(): void
    {
        $this->seedKeys();
        $this->migration->up();

        $this->migration->up();

        $this->assertSame(2, ApiToken::query()->count());
    }
}
