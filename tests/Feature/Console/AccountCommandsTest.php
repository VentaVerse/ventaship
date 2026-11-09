<?php

namespace Tests\Feature\Console;

use App\Couriers\AccountSettings;
use App\Models\CourierAccount;
use App\Models\CourierAccountSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsCourierAccounts;
use Tests\TestCase;

class AccountCommandsTest extends TestCase
{
    use BuildsCourierAccounts, RefreshDatabase;

    public function test_set_secret_is_encrypted_and_masked_on_show(): void
    {
        $account = CourierAccount::factory()->create(['courier' => 'quadx']);

        $this->artisan('ventaship:account-set', ['account' => $account->public_id, 'key' => 'quadx_sandbox_api_key', '--secret' => true])
            ->expectsQuestion('Value for quadx_sandbox_api_key', 'qx-hidden-key-WXYZ')
            ->assertSuccessful();
        $this->artisan('ventaship:account-set', ['account' => $account->public_id, 'key' => 'quadx_default_parcel', 'value' => 'box'])
            ->assertSuccessful();

        $raw = CourierAccountSetting::query()->where('key', 'quadx_sandbox_api_key')->value('value');
        $this->assertStringNotContainsString('qx-hidden-key', $raw);
        $this->assertSame('qx-hidden-key-WXYZ', AccountSettings::for($account)->secret('quadx_sandbox_api_key'));

        Artisan::call('ventaship:account-show', ['account' => $account->public_id]);
        $output = Artisan::output();
        $this->assertStringContainsString('quadx_sandbox_api_key', $output);
        $this->assertStringContainsString('WXYZ', $output);
        $this->assertStringNotContainsString('qx-hidden-key', $output);
        $this->assertStringNotContainsString($raw, $output);
        $this->assertMatchesRegularExpression('/quadx_default_parcel\s.*box/', $output);
    }

    public function test_set_refuses_unknown_key_and_mismatched_secret_flag(): void
    {
        $account = CourierAccount::factory()->create(['courier' => 'quadx']);

        $this->artisan('ventaship:account-set', ['account' => $account->public_id, 'key' => 'quadx_enabled', 'value' => '1'])->assertFailed();
        $this->artisan('ventaship:account-set', ['account' => $account->public_id, 'key' => 'quadx_sandbox_api_key', 'value' => 'plain'])->assertFailed();
        $this->artisan('ventaship:account-set', ['account' => $account->public_id, 'key' => 'quadx_default_parcel', 'value' => 'box', '--secret' => true])->assertFailed();
        $this->artisan('ventaship:account-set', ['account' => $account->public_id, 'key' => 'quadx_default_parcel', 'value' => 'crate'])->assertFailed();
        $this->artisan('ventaship:account-set', ['account' => 'acc_nope', 'key' => 'quadx_default_parcel', 'value' => 'box'])->assertFailed();

        $this->assertSame(0, CourierAccountSetting::query()->count());
    }

    public function test_create_prints_id_and_webhook_url_and_stores_token_hashed(): void
    {
        config(['app.url' => 'https://ship.example.test']);

        $this->assertSame(0, Artisan::call('ventaship:account-create', ['courier' => 'quadx', 'label' => 'Main QuadX']));
        $output = Artisan::output();

        $account = CourierAccount::query()->sole();
        $this->assertStringContainsString($account->public_id, $output);
        $this->assertStringStartsWith('acc_', $account->public_id);
        $this->assertSame('sandbox', $account->mode);
        $this->assertSame('active', $account->status);
        $this->assertFalse($account->is_default);

        $this->assertMatchesRegularExpression('#https://ship\.example\.test/webhooks/quadx\?token=([A-Za-z0-9]{40})#', $output);
        preg_match('#\?token=([A-Za-z0-9]{40})#', $output, $m);
        $this->assertSame($m[1], $account->webhook_token);
        $this->assertSame(hash('sha256', $m[1]), $account->webhook_token_hash);
        $this->assertStringNotContainsString($m[1], (string) $account->getRawOriginal('webhook_token'));
    }

    public function test_create_prints_the_webhook_url_on_app_url(): void
    {
        config(['app.url' => 'https://ship.example.test/']);

        $this->assertSame(0, Artisan::call('ventaship:account-create', ['courier' => 'quadx', 'label' => 'Main QuadX']));

        $this->assertMatchesRegularExpression('#https://ship\.example\.test/webhooks/quadx\?token=[A-Za-z0-9]{40}#', Artisan::output());
    }

    public function test_create_prints_the_spx_path_token_url_and_none_for_lbc(): void
    {
        config(['app.url' => 'https://ship.example.test']);

        Artisan::call('ventaship:account-create', ['courier' => 'spx', 'label' => 'Main SPX']);
        $this->assertMatchesRegularExpression('#https://ship\.example\.test/webhooks/spx/[A-Za-z0-9]{40}\s#', Artisan::output());

        Artisan::call('ventaship:account-create', ['courier' => 'lbc', 'label' => 'Main LBC']);
        $this->assertStringNotContainsString('Webhook URL', Artisan::output());
    }

    public function test_setting_the_spx_app_id_sets_the_account_external_ref(): void
    {
        $account = CourierAccount::factory()->create(['courier' => 'spx']);

        $this->artisan('ventaship:account-set', ['account' => $account->public_id, 'key' => 'spx_sandbox_app_id', 'value' => '424242'])
            ->assertSuccessful();

        $this->assertSame('424242', $account->refresh()->external_ref);
    }

    public function test_create_rejects_unknown_courier_and_mode(): void
    {
        $this->artisan('ventaship:account-create', ['courier' => 'dhl', 'label' => 'X'])->assertFailed();
        $this->artisan('ventaship:account-create', ['courier' => 'quadx', 'label' => 'X', '--mode' => 'live'])->assertFailed();

        $this->assertSame(0, CourierAccount::query()->count());
    }

    public function test_default_flag_moves_the_slot(): void
    {
        $this->artisan('ventaship:account-create', ['courier' => 'quadx', 'label' => 'First', '--default' => true])->assertSuccessful();
        $this->artisan('ventaship:account-create', ['courier' => 'quadx', 'label' => 'Second', '--default' => true])->assertSuccessful();

        $first = CourierAccount::query()->where('label', 'First')->sole();
        $second = CourierAccount::query()->where('label', 'Second')->sole();
        $this->assertFalse($first->is_default);
        $this->assertNull($first->default_slot);
        $this->assertTrue($second->is_default);
        $this->assertSame('quadx', $second->default_slot);
    }

    public function test_deleting_a_default_account_frees_its_slot(): void
    {
        $this->artisan('ventaship:account-create', ['courier' => 'quadx', 'label' => 'Old', '--default' => true])->assertSuccessful();
        $old = CourierAccount::query()->sole();

        $old->delete();

        $trashed = CourierAccount::withTrashed()->find($old->id);
        $this->assertNotNull($trashed->deleted_at);
        $this->assertNull($trashed->default_slot);
        $this->assertFalse($trashed->is_default);

        $this->artisan('ventaship:account-create', ['courier' => 'quadx', 'label' => 'New', '--default' => true])->assertSuccessful();
        $this->assertSame('quadx', CourierAccount::query()->where('label', 'New')->sole()->default_slot);
    }

    public function test_account_test_runs_test_connection(): void
    {
        Http::fake(['api.staging.quadx.xyz/*' => Http::response($this->quadxFixture('statuses'))]);
        $account = $this->quadxAccount();

        $this->artisan('ventaship:account-test', ['account' => $account->public_id])
            ->expectsOutputToContain('Connected')
            ->assertSuccessful();

        $account->refresh();
        $this->assertTrue($account->last_check_ok);
        $this->assertNotNull($account->last_check_at);
    }

    public function test_account_test_reports_failure(): void
    {
        Http::fake(['api.staging.quadx.xyz/*' => Http::response($this->quadxFixture('unauthorized'), 401)]);
        $account = $this->quadxAccount();

        $this->artisan('ventaship:account-test', ['account' => $account->public_id])
            ->expectsOutputToContain('Unauthenticated')
            ->assertFailed();

        $this->assertFalse($account->fresh()->last_check_ok);
    }
}
