<?php

namespace Tests\Feature\Couriers;

use App\Couriers\AccountSettings;
use App\Couriers\CourierAccountException;
use App\Couriers\CourierAccounts;
use App\Couriers\CourierRegistry;
use App\Couriers\FakeCourier;
use App\Models\AuditEntry;
use App\Models\CourierAccount;
use App\Models\CourierAccountSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsCourierAccounts;
use Tests\TestCase;

class CourierAccountsServiceTest extends TestCase
{
    use BuildsCourierAccounts, RefreshDatabase;

    private const ACTOR = ['type' => 'admin', 'id' => '7', 'ip' => '10.0.0.9'];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function service(): CourierAccounts
    {
        return app(CourierAccounts::class);
    }

    public function test_create_matches_command_rules_and_audits(): void
    {
        $first = $this->service()->create('quadx', 'Main', 'staging', true, self::ACTOR);
        $second = $this->service()->create('quadx', 'Other', 'production', true);

        $this->assertSame('quadx', $first->courier);
        $this->assertSame(40, strlen($first->webhook_token));
        $this->assertSame(hash('sha256', $first->webhook_token), $first->webhook_token_hash);
        $this->assertNull($first->fresh()->default_slot);
        $this->assertSame('quadx', $second->fresh()->default_slot);

        $audit = AuditEntry::query()->where('action', 'courier_account.created')->orderBy('id')->first();
        $this->assertSame('admin', $audit->actor_type);
        $this->assertSame('7', $audit->actor_id);
        $this->assertSame('10.0.0.9', $audit->ip);
        $this->assertSame($first->public_id, $audit->subject_id);
        $this->assertSame('system', AuditEntry::query()->where('action', 'courier_account.created')->orderByDesc('id')->first()->actor_type);

        foreach ([['nope', 'X', 'staging'], ['quadx', '', 'staging'], ['quadx', 'X', 'live']] as [$c, $l, $m]) {
            try {
                $this->service()->create($c, $l, $m, false);
                $this->fail('Expected refusal');
            } catch (CourierAccountException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_set_setting_rejects_unknown_key_and_bad_option(): void
    {
        $account = CourierAccount::factory()->create(['courier' => 'quadx']);

        foreach ([['quadx_enabled', '1'], ['quadx_default_parcel', 'crate'], ['quadx_default_parcel', ''], ['quadx_default_parcel', str_repeat('a', 2049)]] as [$k, $v]) {
            try {
                $this->service()->setSetting($account, $k, $v);
                $this->fail('Expected refusal');
            } catch (CourierAccountException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(0, CourierAccountSetting::query()->count());

        $this->service()->setSetting($account, 'quadx_default_parcel', 'box', self::ACTOR);
        $this->assertSame('box', AccountSettings::for($account)->read('quadx_default_parcel'));
    }

    public function test_set_secret_stores_encrypted_and_audit_has_key_only(): void
    {
        $account = CourierAccount::factory()->create(['courier' => 'quadx']);

        $this->service()->setSetting($account, 'quadx_staging_api_key', 'qx-hidden-key-WXYZ', self::ACTOR);

        $raw = CourierAccountSetting::query()->where('key', 'quadx_staging_api_key')->value('value');
        $this->assertStringNotContainsString('qx-hidden-key', $raw);
        $this->assertSame('qx-hidden-key-WXYZ', AccountSettings::for($account)->secret('quadx_staging_api_key'));

        $audit = AuditEntry::query()->where('action', 'courier_account.setting_changed')->firstOrFail();
        $this->assertSame('quadx_staging_api_key', $audit->changes['key']);
        $this->assertSame(['key', 'value'], array_keys($audit->changes));
        $this->assertSame(['admin', '7'], [$audit->actor_type, $audit->actor_id]);
        $this->assertStringNotContainsString('WXYZ', json_encode(AuditEntry::query()->get()->toArray()));
    }

    public function test_set_mode_lbc_syncs_key_set_and_keeps_other_keys(): void
    {
        $account = CourierAccount::factory()->create(['courier' => 'lbc', 'mode' => 'staging']);
        AccountSettings::for($account)->writeSecret('lbc_test_api_key', 'keep-me-1234');

        $this->service()->setMode($account, 'production', self::ACTOR);

        $this->assertSame('production', $account->fresh()->mode);
        $this->assertSame('live', AccountSettings::for($account)->read('lbc_mode'));
        $this->assertSame('keep-me-1234', AccountSettings::for($account)->secret('lbc_test_api_key'));
        $this->assertSame(1, AuditEntry::query()->where('action', 'courier_account.mode_changed')->where('actor_id', '7')->count());

        $keyRow = AuditEntry::query()->where('action', 'courier_account.setting_changed')->where('changes->key', 'lbc_mode')->latest('id')->first();
        $this->assertSame(['admin', '7', '10.0.0.9'], [$keyRow->actor_type, $keyRow->actor_id, $keyRow->ip]);

        $this->service()->setMode($account->fresh(), 'staging');
        $this->assertSame('test', AccountSettings::for($account)->read('lbc_mode'));

        $this->expectException(CourierAccountException::class);
        $this->service()->setMode($account->fresh(), 'uat');
    }

    public function test_set_mode_unchanged_resyncs_drifted_lbc_key_set(): void
    {
        $account = CourierAccount::factory()->create(['courier' => 'lbc', 'mode' => 'production']);
        AccountSettings::for($account)->write('lbc_mode', 'test');

        $this->assertNull($this->service()->setMode($account, 'production', self::ACTOR));

        $this->assertSame('live', AccountSettings::for($account)->read('lbc_mode'));
        $this->assertSame(0, AuditEntry::query()->where('action', 'courier_account.mode_changed')->count());
    }

    public function test_deactivating_clears_the_default_slot_and_activating_does_not_restore_it(): void
    {
        $account = $this->service()->create('quadx', 'Main', 'staging', true);

        $this->assertTrue($this->service()->setActive($account, false, self::ACTOR));

        $fresh = $account->fresh();
        $this->assertFalse((bool) $fresh->is_default);
        $this->assertNull($fresh->default_slot);
        $audit = AuditEntry::query()->where('action', 'courier_account.default_changed')->firstOrFail();
        $this->assertSame(['is_default' => false], $audit->changes);
        $this->assertSame('admin', $audit->actor_type);

        $this->service()->setActive($account, true, self::ACTOR);
        $this->assertFalse((bool) $account->fresh()->is_default);
        $this->assertNull($account->fresh()->default_slot);
    }

    public function test_real_adapter_timeouts_show_the_short_no_answer_message(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out after 40001 milliseconds for https://api.staging.quadx.xyz/v2/orders/statuses'));

        foreach (['quadx' => $this->quadxAccount(), 'spx' => $this->spxAccount()] as $courier => $account) {
            $result = $this->service()->test($account);

            $this->assertSame(['ok' => false, 'message' => 'No answer from ' . ($courier === 'spx' ? 'SPX' : 'QuadX') . '.'], $result);
            $this->assertFalse((bool) $account->fresh()->last_check_ok);
        }
    }

    public function test_real_adapter_other_failures_stay_short_without_urls(): void
    {
        Http::fake(fn () => Http::response(['message' => str_repeat('x', 400)], 500));

        $result = $this->service()->test($this->quadxAccount());

        $this->assertFalse($result['ok']);
        $this->assertLessThanOrEqual(160, mb_strlen($result['message']));
    }

    public function test_test_connection_timeout_is_failure_not_exception(): void
    {
        $account = CourierAccount::factory()->create(['courier' => 'quadx']);
        CourierRegistry::fake('quadx', FakeCourier::make('quadx')->throws(new ConnectionException('cURL error 28: timed out'), 'testConnection'));

        $result = $this->service()->test($account);

        $this->assertSame(['ok' => false, 'message' => 'No answer from QuadX.'], $result);
        $fresh = $account->fresh();
        $this->assertFalse((bool) $fresh->last_check_ok);
        $this->assertNotNull($fresh->last_check_at);

        CourierRegistry::fake('quadx', FakeCourier::make('quadx'));
        $this->assertTrue($this->service()->test($account)['ok']);
        $this->assertTrue((bool) $account->fresh()->last_check_ok);
    }

    public function test_reveal_returns_plaintext_and_audits(): void
    {
        $account = CourierAccount::factory()->create(['courier' => 'quadx']);
        AccountSettings::for($account)->writeSecret('quadx_staging_api_key', 'qx-reveal-me-9876');

        $this->assertSame('qx-reveal-me-9876', $this->service()->revealSecret($account, 'quadx_staging_api_key', self::ACTOR));

        $audit = AuditEntry::query()->where('action', 'courier_account.secret_viewed')->firstOrFail();
        $this->assertSame(['key' => 'quadx_staging_api_key'], $audit->changes);
        $this->assertSame('10.0.0.9', $audit->ip);
        $this->assertStringNotContainsString('reveal-me', json_encode($audit->toArray()));

        $this->expectException(CourierAccountException::class);
        $this->service()->revealSecret($account, 'quadx_default_parcel', self::ACTOR);
    }

    public function test_masked_shows_length_and_last4_only(): void
    {
        $account = CourierAccount::factory()->create(['courier' => 'quadx']);
        AccountSettings::for($account)->writeSecret('quadx_staging_api_key', 'qx-staging-key-0001');

        $this->assertSame(['length' => 19, 'last4' => '0001'], $this->service()->masked($account, 'quadx_staging_api_key'));
        $this->assertNull($this->service()->masked($account, 'quadx_staging_secret_key'));
    }

    public function test_webhook_address_is_built_on_app_url(): void
    {
        config(['app.url' => 'https://ship.example.com/']);
        $quadx = $this->service()->create('quadx', 'QuadX', 'staging', true);
        $lbc = $this->service()->create('lbc', 'LBC', 'staging', true);

        $this->assertSame('https://ship.example.com/webhooks/quadx?token='.$quadx->webhook_token, $this->service()->webhookAddress($quadx));
        $this->assertStringNotContainsString($quadx->webhook_token, (string) $this->service()->webhookAddress($quadx, true));
        $this->assertNull($this->service()->webhookAddress($lbc));

        config(['app.url' => '']);
        $this->assertNull($this->service()->webhookAddress($quadx));
    }
}
