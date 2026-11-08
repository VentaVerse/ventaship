<?php

namespace Tests\Feature\Couriers;

use App\Couriers\AccountSettings;
use App\Models\AuditEntry;
use App\Models\CourierAccount;
use App\Models\CourierAccountSetting;
use App\Support\Vault\CredentialVault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AccountSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_secret_round_trip_and_audit(): void
    {
        $account = CourierAccount::factory()->create();

        AccountSettings::for($account)->writeSecret('quadx_staging_api_key', 'qx-live-key-ABCD');
        AccountSettings::for($account)->write('quadx_default_parcel', 'box');

        $raw = CourierAccountSetting::query()->where('key', 'quadx_staging_api_key')->value('value');
        $this->assertNotSame('qx-live-key-ABCD', $raw);
        $this->assertStringNotContainsString('qx-live-key', $raw);
        $this->assertSame('qx-live-key-ABCD', app(CredentialVault::class)->decrypt($raw));

        $settings = AccountSettings::for($account);
        $this->assertSame('qx-live-key-ABCD', $settings->secret('quadx_staging_api_key'));
        $this->assertSame($raw, $settings->read('quadx_staging_api_key'));
        $this->assertSame('box', $settings->read('quadx_default_parcel'));
        $this->assertNull($settings->secret('quadx_staging_secret_key'));
        $this->assertSame('next_day', $settings->read('quadx_default_service', 'next_day'));

        $audits = AuditEntry::query()->orderBy('id')->get();
        $this->assertCount(2, $audits);
        $this->assertSame('courier_account.setting_changed', $audits[0]->action);
        $this->assertSame('courier_account', $audits[0]->subject_type);
        $this->assertSame($account->public_id, $audits[0]->subject_id);
        $this->assertSame(['key' => 'quadx_staging_api_key', 'value' => '•••• changed'], $audits[0]->changes);
        $this->assertSame(['key' => 'quadx_default_parcel', 'from' => null, 'to' => 'box'], $audits[1]->changes);
        $this->assertStringNotContainsString('qx-live-key', (string) DB::table('audit_entries')->pluck('changes')->implode('|'));
    }

    public function test_settings_are_per_account(): void
    {
        $a = CourierAccount::factory()->create();
        $b = CourierAccount::factory()->create();

        AccountSettings::for($a)->writeSecret('quadx_staging_api_key', 'key-of-account-a');
        AccountSettings::for($b)->writeSecret('quadx_staging_api_key', 'key-of-account-b');

        $this->assertSame('key-of-account-a', AccountSettings::for($a)->secret('quadx_staging_api_key'));
        $this->assertSame('key-of-account-b', AccountSettings::for($b)->secret('quadx_staging_api_key'));
    }

    public function test_loads_rows_once_and_sees_its_own_writes(): void
    {
        $account = CourierAccount::factory()->create();
        AccountSettings::for($account)->write('quadx_default_parcel', 'box');

        DB::enableQueryLog();
        $settings = AccountSettings::for($account);
        $settings->read('quadx_default_parcel');
        $settings->read('quadx_default_service');
        $settings->secret('quadx_staging_api_key');
        $this->assertCount(1, DB::getQueryLog());
        DB::disableQueryLog();

        $settings->write('quadx_default_parcel', 'small-pouch');
        $this->assertSame('small-pouch', $settings->read('quadx_default_parcel'));
    }

    public function test_writing_null_removes_the_value(): void
    {
        $account = CourierAccount::factory()->create();
        $settings = AccountSettings::for($account);
        $settings->writeSecret('quadx_staging_api_key', 'to-be-removed-1');

        $settings->writeSecret('quadx_staging_api_key', null);

        $this->assertNull($settings->secret('quadx_staging_api_key'));
        $this->assertNull(AccountSettings::for($account)->read('quadx_staging_api_key'));
        $this->assertSame(0, CourierAccountSetting::query()->count());
        $this->assertSame('•••• removed', AuditEntry::query()->latest('id')->first()->changes['value']);
    }

    public function test_undecryptable_secret_reads_as_null(): void
    {
        $account = CourierAccount::factory()->create();
        CourierAccountSetting::query()->create(['courier_account_id' => $account->id, 'key' => 'quadx_staging_api_key', 'value' => 'not-a-cipher']);

        $this->assertNull(AccountSettings::for($account)->secret('quadx_staging_api_key'));
    }
}
