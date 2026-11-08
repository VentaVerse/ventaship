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
use App\Support\Settings;
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
        $this->assertFalse((bool) $first->allow_live_booking);
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

        $this->service()->setMode($account->fresh(), 'staging');
        $this->assertSame('test', AccountSettings::for($account)->read('lbc_mode'));

        $this->expectException(CourierAccountException::class);
        $this->service()->setMode($account->fresh(), 'uat');
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

    public function test_public_address_validation(): void
    {
        $admin = \App\Models\Admin::query()->create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'correct-horse-battery']);

        foreach (['http://x.example', 'https://x.example/', 'https://x.example/path', 'https://x.example?a=1', 'x.example', 'https://'] as $bad) {
            $this->actingAs($admin)->put(route('settings.update'), ['tab' => 'couriers', 'webhooks_public_base_url' => $bad])
                ->assertSessionHasErrors(['webhooks_public_base_url' => 'Use https://your-domain']);
        }
        $this->assertNull((new Settings)->publicBaseUrl());

        $this->actingAs($admin)->put(route('settings.update'), ['tab' => 'couriers', 'webhooks_public_base_url' => 'https://ship.example.com'])
            ->assertSessionHasNoErrors();
        $this->assertSame('https://ship.example.com', (new Settings)->publicBaseUrl());
        $this->assertTrue(AuditEntry::query()->where('action', 'setting.changed')->where('subject_id', 'webhooks.public_base_url')->exists());

        $this->actingAs($admin)->put(route('settings.update'), ['tab' => 'couriers', 'webhooks_public_base_url' => ''])->assertSessionHasNoErrors();
        $this->assertNull((new Settings)->publicBaseUrl());
    }
}
