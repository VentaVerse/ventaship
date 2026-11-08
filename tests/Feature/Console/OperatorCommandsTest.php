<?php

namespace Tests\Feature\Console;

use App\Models\AuditEntry;
use App\Models\CourierAccount;
use App\Models\Setting;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** ventaship:account-mode and ventaship:setting-set: the runbook's operator switches, each audited. */
class OperatorCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_mode_switches_prints_and_audits(): void
    {
        $account = CourierAccount::factory()->create(['courier' => 'quadx', 'mode' => 'staging']);

        $this->artisan('ventaship:account-mode', ['account' => $account->public_id, 'mode' => 'production'])
            ->expectsOutputToContain("{$account->public_id} (quadx) mode: staging → production")
            ->assertSuccessful();

        $this->assertSame('production', $account->refresh()->mode);
        $audit = AuditEntry::query()->sole();
        $this->assertSame('system', $audit->actor_type);
        $this->assertSame('courier_account.mode_changed', $audit->action);
        $this->assertSame('courier_account', $audit->subject_type);
        $this->assertSame($account->public_id, $audit->subject_id);
        $this->assertSame(['mode' => ['from' => 'staging', 'to' => 'production']], $audit->changes);
    }

    public function test_account_mode_accepts_uat_for_spx_only(): void
    {
        $spx = CourierAccount::factory()->create(['courier' => 'spx', 'mode' => 'production']);
        $quadx = CourierAccount::factory()->create(['courier' => 'quadx', 'mode' => 'staging']);

        $this->artisan('ventaship:account-mode', ['account' => $spx->public_id, 'mode' => 'uat'])->assertSuccessful();
        $this->assertSame('uat', $spx->refresh()->mode);

        $this->artisan('ventaship:account-mode', ['account' => $quadx->public_id, 'mode' => 'uat'])
            ->expectsOutputToContain('The mode for quadx must be one of: staging, production.')
            ->assertFailed();
        $this->assertSame('staging', $quadx->refresh()->mode);
        $this->assertSame(1, AuditEntry::query()->count());
    }

    public function test_account_mode_refuses_unknown_accounts_and_skips_no_ops(): void
    {
        $account = CourierAccount::factory()->create(['courier' => 'quadx', 'mode' => 'staging']);

        $this->artisan('ventaship:account-mode', ['account' => 'acc_nope', 'mode' => 'staging'])->assertFailed();
        $this->artisan('ventaship:account-mode', ['account' => $account->public_id, 'mode' => 'Production'])->assertFailed();
        $this->artisan('ventaship:account-mode', ['account' => $account->public_id, 'mode' => 'staging'])
            ->expectsOutputToContain('already in staging mode')
            ->assertSuccessful();

        $account->delete();
        $this->artisan('ventaship:account-mode', ['account' => $account->public_id, 'mode' => 'production'])->assertFailed();

        $this->assertSame(0, AuditEntry::query()->count());
    }

    public function test_setting_set_stores_a_safety_boolean_and_audits(): void
    {
        $this->artisan('ventaship:setting-set', ['key' => 'safety.allow_live_spx', 'value' => 'true'])
            ->expectsOutputToContain('safety.allow_live_spx: null → true')
            ->assertSuccessful();

        $this->assertTrue(app(Settings::class)->get('safety.allow_live_spx'));
        $this->assertTrue((new Settings)->get('safety.allow_live_spx'));

        $this->artisan('ventaship:setting-set', ['key' => 'safety.allow_live_spx', 'value' => 'false'])->assertSuccessful();
        $this->assertFalse((new Settings)->get('safety.allow_live_spx'));

        $audits = AuditEntry::query()->orderBy('id')->get();
        $this->assertSame(['setting.changed', 'setting.changed'], $audits->pluck('action')->all());
        $this->assertSame('setting', $audits[0]->subject_type);
        $this->assertSame('safety.allow_live_spx', $audits[0]->subject_id);
        $this->assertSame(['value' => ['from' => null, 'to' => true]], $audits[0]->changes);
        $this->assertSame(['value' => ['from' => true, 'to' => false]], $audits[1]->changes);
    }

    /** @return array<string, array{string}> */
    public static function looseBooleans(): array
    {
        return ['1' => ['1'], 'yes' => ['yes'], 'on' => ['on'], 'TRUE' => ['TRUE'], 'empty' => [''], 'quoted' => ['"true"']];
    }

    #[DataProvider('looseBooleans')]
    public function test_setting_set_refuses_anything_but_true_or_false_for_safety_keys(string $value): void
    {
        $this->artisan('ventaship:setting-set', ['key' => 'safety.allow_live_lbc', 'value' => $value])->assertFailed();
        $this->artisan('ventaship:setting-set', ['key' => 'safety.allow_live_lbc', 'value' => $value, '--json' => true])->assertFailed();

        $this->assertSame(0, Setting::query()->count());
        $this->assertSame(0, AuditEntry::query()->count());
    }

    public function test_setting_set_stores_strings_and_json(): void
    {
        $this->artisan('ventaship:setting-set', ['key' => 'ops.contact', 'value' => 'ops@example.test'])->assertSuccessful();
        $this->artisan('ventaship:setting-set', ['key' => 'ops.limits', 'value' => '{"max": 5}', '--json' => true])->assertSuccessful();

        $settings = new Settings;
        $this->assertSame('ops@example.test', $settings->get('ops.contact'));
        $this->assertSame(['max' => 5], $settings->get('ops.limits'));
        $this->assertSame(2, AuditEntry::query()->count());
    }

    public function test_setting_set_refuses_bad_json_and_bad_keys(): void
    {
        $this->artisan('ventaship:setting-set', ['key' => 'ops.limits', 'value' => '{max: 5}', '--json' => true])->assertFailed();
        $this->artisan('ventaship:setting-set', ['key' => 'Bad Key', 'value' => 'x'])->assertFailed();
        $this->artisan('ventaship:setting-set', ['key' => str_repeat('a', 65), 'value' => 'x'])->assertFailed();

        $this->assertSame(0, Setting::query()->count());
        $this->assertSame(0, AuditEntry::query()->count());
    }
}
