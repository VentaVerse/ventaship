<?php

namespace Tests\Feature\Console;

use App\Models\AuditEntry;
use App\Models\CourierAccount;
use App\Models\Setting;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** ventaship:account-mode and ventaship:setting-set: the runbook's operator switches, each audited. */
class OperatorCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_mode_switches_prints_and_audits(): void
    {
        $account = CourierAccount::factory()->create(['courier' => 'quadx', 'mode' => 'sandbox']);

        $this->artisan('ventaship:account-mode', ['account' => $account->public_id, 'mode' => 'production'])
            ->expectsOutputToContain("{$account->public_id} (quadx) mode: sandbox → production")
            ->assertSuccessful();

        $this->assertSame('production', $account->refresh()->mode);
        $audit = AuditEntry::query()->sole();
        $this->assertSame('system', $audit->actor_type);
        $this->assertSame('courier_account.mode_changed', $audit->action);
        $this->assertSame('courier_account', $audit->subject_type);
        $this->assertSame($account->public_id, $audit->subject_id);
        $this->assertSame(['mode' => ['from' => 'sandbox', 'to' => 'production']], $audit->changes);
    }

    public function test_account_mode_takes_sandbox_or_production_only(): void
    {
        $spx = CourierAccount::factory()->create(['courier' => 'spx', 'mode' => 'production']);

        $this->artisan('ventaship:account-mode', ['account' => $spx->public_id, 'mode' => 'sandbox'])->assertSuccessful();
        $this->assertSame('sandbox', $spx->refresh()->mode);

        foreach (['uat', 'staging', 'live'] as $old) {
            $this->artisan('ventaship:account-mode', ['account' => $spx->public_id, 'mode' => $old])
                ->expectsOutputToContain('The environment must be sandbox or production.')
                ->assertFailed();
        }
        $this->assertSame('sandbox', $spx->refresh()->mode);
        $this->assertSame(1, AuditEntry::query()->count());
    }

    public function test_account_mode_refuses_unknown_accounts_and_skips_no_ops(): void
    {
        $account = CourierAccount::factory()->create(['courier' => 'quadx', 'mode' => 'sandbox']);

        $this->artisan('ventaship:account-mode', ['account' => 'acc_nope', 'mode' => 'sandbox'])->assertFailed();
        $this->artisan('ventaship:account-mode', ['account' => $account->public_id, 'mode' => 'Production'])->assertFailed();
        $this->artisan('ventaship:account-mode', ['account' => $account->public_id, 'mode' => 'sandbox'])
            ->expectsOutputToContain('already in sandbox mode')
            ->assertSuccessful();

        $account->delete();
        $this->artisan('ventaship:account-mode', ['account' => $account->public_id, 'mode' => 'production'])->assertFailed();

        $this->assertSame(0, AuditEntry::query()->count());
    }

    public function test_setting_set_audits_each_change(): void
    {
        $this->artisan('ventaship:setting-set', ['key' => 'ops.paused', 'value' => 'true', '--json' => true])
            ->expectsOutputToContain('ops.paused: null → true')
            ->assertSuccessful();
        $this->artisan('ventaship:setting-set', ['key' => 'ops.paused', 'value' => 'false', '--json' => true])->assertSuccessful();
        $this->assertFalse((new Settings)->get('ops.paused'));

        $audits = AuditEntry::query()->orderBy('id')->get();
        $this->assertSame(['setting.changed', 'setting.changed'], $audits->pluck('action')->all());
        $this->assertSame('setting', $audits[0]->subject_type);
        $this->assertSame('ops.paused', $audits[0]->subject_id);
        $this->assertSame(['value' => ['from' => null, 'to' => true]], $audits[0]->changes);
        $this->assertSame(['value' => ['from' => true, 'to' => false]], $audits[1]->changes);
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
