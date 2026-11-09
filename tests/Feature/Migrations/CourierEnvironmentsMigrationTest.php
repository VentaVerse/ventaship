<?php

namespace Tests\Feature\Migrations;

use App\Couriers\AccountSettings;
use App\Couriers\CourierRegistry;
use App\Couriers\QuadX\QuadXClient;
use App\Couriers\Spx\SpxClient;
use App\Models\CourierAccount;
use App\Support\Vault\CredentialVault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** The migration that moves courier accounts to the two environments, sandbox and production. */
class CourierEnvironmentsMigrationTest extends TestCase
{
    use RefreshDatabase;

    private object $migration;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->migration = require database_path('migrations/2026_11_09_000800_courier_accounts_sandbox_production.php');
    }

    /** An account row as it was stored before: old mode and raw old setting keys (secrets vault-encrypted). */
    private function legacy(string $courier, string $mode, array $plain = [], array $secrets = []): CourierAccount
    {
        $account = CourierAccount::factory()->create(['courier' => $courier]);
        DB::table('courier_accounts')->where('id', $account->id)->update(['mode' => $mode]);
        $vault = app(CredentialVault::class);
        foreach ($plain + array_map(fn (string $v) => $vault->encrypt($v), $secrets) as $key => $value) {
            DB::table('courier_account_settings')->insert([
                'courier_account_id' => $account->id, 'key' => $key, 'value' => $value, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $account;
    }

    /** @return array<string, string> key => stored value */
    private function keys(CourierAccount $account): array
    {
        return DB::table('courier_account_settings')->where('courier_account_id', $account->id)->orderBy('key')->pluck('value', 'key')->all();
    }

    public function test_staging_quadx_keeps_its_keys_in_the_sandbox_slots(): void
    {
        $qx = $this->legacy('quadx', 'staging', ['quadx_default_parcel' => 'box'], [
            'quadx_staging_api_key' => 'qx-api-0001', 'quadx_staging_secret_key' => 'qx-secret-0001', 'quadx_production_api_key' => 'qx-live-0001',
        ]);

        $this->migration->up();

        $qx->refresh();
        $this->assertSame('sandbox', $qx->mode);
        $this->assertSame(['quadx_default_parcel', 'quadx_production_api_key', 'quadx_sandbox_api_key', 'quadx_sandbox_secret_key'], array_keys($this->keys($qx)));

        $client = new QuadXClient(AccountSettings::for($qx));
        $this->assertSame('sandbox', $client->mode());
        $this->assertSame('https://api.staging.quadx.xyz/v2', $client->baseUrl());
        $this->assertTrue($client->isConfigured());
        $this->assertSame('qx-api-0001', AccountSettings::for($qx)->secret('quadx_sandbox_api_key'));
        $this->assertSame('qx-secret-0001', AccountSettings::for($qx)->secret('quadx_sandbox_secret_key'));
    }

    public function test_uat_spx_keeps_its_credentials_in_the_sandbox_slots_and_the_uat_host(): void
    {
        $spx = $this->legacy('spx', 'uat', ['spx_app_id' => '424242', 'spx_user_id' => '999999', 'spx_pouch_presets' => '[]'], [
            'spx_secret_key' => 'app-secret', 'spx_user_secret' => 'user-secret',
        ]);
        DB::table('courier_accounts')->where('id', $spx->id)->update(['external_ref' => '424242']);

        $this->migration->up();

        $spx->refresh();
        $this->assertSame('sandbox', $spx->mode);
        $this->assertSame('424242', $spx->external_ref);
        $this->assertSame(
            ['spx_pouch_presets', 'spx_sandbox_app_id', 'spx_sandbox_secret_key', 'spx_sandbox_user_id', 'spx_sandbox_user_secret'],
            array_keys($this->keys($spx)),
        );
        $settings = AccountSettings::for($spx);
        $this->assertSame('424242', $settings->read('spx_sandbox_app_id'));
        $this->assertSame('app-secret', $settings->secret('spx_sandbox_secret_key'));
        $this->assertSame('999999', $settings->read('spx_sandbox_user_id'));
        $this->assertSame('user-secret', $settings->secret('spx_sandbox_user_secret'));

        $client = new SpxClient($settings);
        $this->assertSame('https://test-stable.spx.ph/', $client->baseUrl());
        $this->assertTrue($client->isConfigured());
        $this->assertTrue($client->hasAccountCredentials());
    }

    public function test_spx_staging_is_sandbox_and_production_keeps_its_credentials_in_production(): void
    {
        $staging = $this->legacy('spx', 'staging', ['spx_app_id' => '1']);
        $live = $this->legacy('spx', 'production', ['spx_app_id' => '2'], ['spx_secret_key' => 'live-secret']);

        $this->migration->up();

        $this->assertSame('sandbox', $staging->refresh()->mode);
        $this->assertSame(['spx_sandbox_app_id'], array_keys($this->keys($staging)));
        $this->assertSame('production', $live->refresh()->mode);
        $this->assertSame(['spx_production_app_id', 'spx_production_secret_key'], array_keys($this->keys($live)));
        $this->assertSame('https://spx.ph/', (new SpxClient(AccountSettings::for($live)))->baseUrl());
    }

    public function test_lbc_key_sets_become_environments_and_the_account_follows_the_key_set_it_sent(): void
    {
        $lbc = $this->legacy('lbc', 'production', ['lbc_mode' => 'test', 'lbc_client_code' => 'DEMO'], [
            'lbc_test_pickup_key' => 'test-pickup', 'lbc_live_pickup_key' => 'live-pickup',
        ]);
        $plain = $this->legacy('lbc', 'production', [], ['lbc_live_track_key' => 'live-track']);

        $this->migration->up();

        $this->assertSame('sandbox', $lbc->refresh()->mode, 'it sent the test keys, so it keeps sending them');
        $this->assertSame(['lbc_client_code', 'lbc_production_pickup_key', 'lbc_sandbox_pickup_key'], array_keys($this->keys($lbc)));
        $this->assertSame('test-pickup', CourierRegistry::for($lbc)->subscriptionKey('pickup'));

        $this->assertSame('production', $plain->refresh()->mode);
        $this->assertSame('live-track', CourierRegistry::for($plain)->subscriptionKey('track'));
    }

    public function test_running_twice_changes_nothing_more_and_never_overwrites_a_new_slot(): void
    {
        $qx = $this->legacy('quadx', 'staging', [], ['quadx_staging_api_key' => 'old', 'quadx_sandbox_api_key' => 'new']);
        $spx = $this->legacy('spx', 'uat', ['spx_app_id' => '424242'], ['spx_user_secret' => 'user-secret']);

        $this->migration->up();
        $first = [$this->keys($qx), $this->keys($spx)];
        $this->migration->up();

        $this->assertSame($first, [$this->keys($qx), $this->keys($spx)]);
        $this->assertSame('new', AccountSettings::for($qx->refresh())->secret('quadx_sandbox_api_key'));
        $this->assertSame('sandbox', $spx->refresh()->mode);
        $this->assertSame('user-secret', AccountSettings::for($spx)->secret('spx_sandbox_user_secret'));
    }

    public function test_new_accounts_default_to_sandbox(): void
    {
        $this->migration->up();

        $account = CourierAccount::query()->create(['courier' => 'quadx', 'label' => 'QuadX', 'status' => 'active']);
        $this->assertSame('sandbox', $account->fresh()->mode);
        $this->assertFalse(Schema::hasColumn('courier_accounts', 'allow_live_booking'));
    }

    public function test_down_restores_the_old_modes_and_keys(): void
    {
        $qx = $this->legacy('quadx', 'staging', [], ['quadx_staging_api_key' => 'qx']);
        $spx = $this->legacy('spx', 'uat', ['spx_app_id' => '424242']);
        $lbc = $this->legacy('lbc', 'production', [], ['lbc_live_pickup_key' => 'live']);

        $this->migration->up();
        $this->migration->down();

        $this->assertSame('staging', $qx->refresh()->mode);
        $this->assertSame(['quadx_staging_api_key'], array_keys($this->keys($qx)));
        $this->assertSame('staging', $spx->refresh()->mode);
        $this->assertSame(['spx_app_id'], array_keys($this->keys($spx)));
        $this->assertSame('production', $lbc->refresh()->mode);
        $this->assertSame(['lbc_live_pickup_key', 'lbc_mode'], array_keys($this->keys($lbc)));
    }
}
