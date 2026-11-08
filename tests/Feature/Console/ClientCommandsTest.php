<?php

namespace Tests\Feature\Console;

use App\Models\ApiToken;
use App\Models\ClientApp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_app_create_prints_secret_and_stores_app(): void
    {
        $this->artisan('ventaship:app-create', ['name' => 'Seller Center', '--webhook-url' => 'https://example.test/hook'])
            ->expectsOutputToContain('app_')
            ->expectsOutputToContain('whsec_')
            ->assertSuccessful();

        $app = ClientApp::query()->sole();
        $this->assertSame('https://example.test/hook', $app->webhook_url);
        $this->assertStringStartsWith('whsec_', $app->webhook_secret);
        $this->assertSame('active', $app->status);
    }

    public function test_key_issue_prints_the_token_only_and_stores_its_hash(): void
    {
        $app = ClientApp::factory()->create();

        $this->assertSame(0, \Illuminate\Support\Facades\Artisan::call('ventaship:key-issue', ['app' => $app->public_id, '--name' => 'Prod']));
        $output = \Illuminate\Support\Facades\Artisan::output();

        $this->assertMatchesRegularExpression('/^\d+\|vsk_[A-Za-z0-9]{48}\n$/', $output);
        $plain = trim($output);
        [$id, $secret] = explode('|', $plain, 2);

        $key = ApiToken::query()->sole();
        $this->assertSame((int) $id, $key->id);
        $this->assertTrue($key->tokenable->is($app));
        $this->assertSame(hash('sha256', $secret), $key->token);
        $this->assertSame(substr($secret, 0, 8), $key->prefix);
        $this->assertSame($plain, $key->token_encrypted);
        $this->assertStringNotContainsString($secret, json_encode($key->getRawOriginal()));
        $this->assertSame('Prod', $key->name);

        $this->getJson('/api/v1/ping', ['Authorization' => 'Bearer '.$plain])->assertOk();
    }

    public function test_key_issue_unknown_app_fails(): void
    {
        $this->artisan('ventaship:key-issue', ['app' => 'app_nope'])->assertFailed();
        $this->assertSame(0, ApiToken::count());
    }

    public function test_key_revoke_deletes_the_token(): void
    {
        $app = ClientApp::factory()->create();
        $this->artisan('ventaship:key-issue', ['app' => $app->public_id])->assertSuccessful();
        $key = ApiToken::query()->sole();

        $this->artisan('ventaship:key-revoke', ['key' => $key->prefix])->assertSuccessful();

        $this->assertNull($key->fresh());
        $this->artisan('ventaship:key-revoke', ['key' => 'vsk_zzzz'])->assertFailed();
    }

    public function test_key_revoke_accepts_numeric_id(): void
    {
        $app = ClientApp::factory()->create();
        $this->artisan('ventaship:key-issue', ['app' => $app->public_id])->assertSuccessful();
        $key = ApiToken::query()->sole();

        $this->artisan('ventaship:key-revoke', ['key' => (string) $key->id])->assertSuccessful();

        $this->assertNull($key->fresh());
        $this->artisan('ventaship:key-revoke', ['key' => '999999'])->assertFailed();
    }
}
