<?php

namespace Tests\Feature\Console;

use App\Models\ApiKey;
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

    public function test_key_issue_prints_key_once_and_stores_hash_only(): void
    {
        $app = ClientApp::factory()->create();

        $this->assertSame(0, \Illuminate\Support\Facades\Artisan::call('ventaship:key-issue', ['app' => $app->public_id, '--name' => 'Prod']));
        $output = \Illuminate\Support\Facades\Artisan::output();

        preg_match('/API key:\s+(vsk_[A-Za-z0-9]{40})/', $output, $m);
        $this->assertNotEmpty($m, 'key printed');
        preg_match('/Signing secret:\s+(\S+)/', $output, $s);

        $key = ApiKey::query()->sole();
        $this->assertSame(hash('sha256', $m[1]), $key->key_hash);
        $this->assertSame(substr($m[1], 0, 8), $key->key_prefix);
        $this->assertSame($s[1], $key->signing_secret);
        $this->assertNotSame($s[1], $key->getRawOriginal('signing_secret'));
        $this->assertStringNotContainsString($m[1], json_encode($key->getRawOriginal()));
        $this->assertSame('Prod', $key->name);
    }

    public function test_key_issue_unknown_app_fails(): void
    {
        $this->artisan('ventaship:key-issue', ['app' => 'app_nope'])->assertFailed();
        $this->assertSame(0, ApiKey::count());
    }

    public function test_key_revoke_sets_revoked_at(): void
    {
        $app = ClientApp::factory()->create();
        $this->artisan('ventaship:key-issue', ['app' => $app->public_id])->assertSuccessful();
        $key = ApiKey::query()->sole();

        $this->artisan('ventaship:key-revoke', ['prefix' => $key->key_prefix])->assertSuccessful();

        $this->assertNotNull($key->fresh()->revoked_at);
        $this->artisan('ventaship:key-revoke', ['prefix' => 'vsk_zzzz'])->assertFailed();
    }
}
