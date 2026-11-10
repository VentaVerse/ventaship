<?php

namespace Tests\Unit\Couriers;

use App\Couriers\AccountSettings;
use App\Couriers\Spx\SpxApiException;
use App\Couriers\Spx\SpxClient;
use App\Models\CourierAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsCourierAccounts;
use Tests\TestCase;

/**
 * SpxClient's HTTP core, on one account's settings.
 *
 * The load-bearing guarantee: the exact bytes SpxClient signs are the exact bytes it sends, with no
 * re-serialization between signing and Http::withBody(), which is the class of bug that produces SPX's
 * `1007 CheckSignVerificationFailedCode`. Every credential is fake and every call is faked.
 */
class SpxClientTest extends TestCase
{
    use BuildsCourierAccounts, RefreshDatabase;

    private function client(CourierAccount $account): SpxClient
    {
        return new SpxClient(AccountSettings::for($account));
    }

    public function test_is_configured_and_has_account_credentials_reflect_stored_settings(): void
    {
        $account = CourierAccount::factory()->create(['courier' => 'spx']);
        $this->assertFalse($this->client($account)->isConfigured());
        $this->assertFalse($this->client($account)->hasAccountCredentials());
        $this->assertSame(['App ID', 'App Secret'], $this->client($account)->missingCredentialLabels());

        AccountSettings::for($account)->write('spx_sandbox_app_id', '424242');
        AccountSettings::for($account)->writeSecret('spx_sandbox_secret_key', 'fake-app-secret-not-real');
        $this->assertTrue($this->client($account)->isConfigured());
        $this->assertFalse($this->client($account)->hasAccountCredentials());

        AccountSettings::for($account)->write('spx_sandbox_user_id', '999999');
        AccountSettings::for($account)->writeSecret('spx_sandbox_user_secret', 'fake-user-secret-not-real');
        $this->assertTrue($this->client($account)->hasAccountCredentials());
        $this->assertSame([], $this->client($account)->missingCredentialLabels());
    }

    public function test_base_url_follows_the_account_mode(): void
    {
        $this->assertSame('https://test-stable.spx.ph/', $this->client($this->spxAccount())->baseUrl());
        $this->assertSame('https://spx.ph/', $this->client($this->spxAccount(['mode' => 'production']))->baseUrl());
    }

    public function test_settings_are_per_account(): void
    {
        $a = $this->spxAccount();
        $b = CourierAccount::factory()->create(['courier' => 'spx']);

        $this->assertTrue($this->client($a)->isConfigured());
        $this->assertFalse($this->client($b)->isConfigured());
    }

    public function test_post_throws_when_not_configured_and_never_sends_a_request(): void
    {
        Http::fake();
        $client = $this->client(CourierAccount::factory()->create(['courier' => 'spx']));

        try {
            $client->post('/open/api/v1/account/verify', ['user_id' => 1, 'user_secret' => 'x']);
            $this->fail('An unconfigured client sent a request.');
        } catch (\RuntimeException) {
        }

        Http::assertNothingSent();
    }

    /**
     * The raw HTTP body byte-for-byte equals the string that was signed, and the check-sign header recomputes to
     * the same value from those same bytes.
     */
    public function test_post_signs_and_sends_the_exact_same_encoded_bytes(): void
    {
        Http::fake([
            '*' => Http::response(['ret_code' => 0, 'message' => 'success', 'data' => ['match_result' => true]], 200),
        ]);

        $body = ['user_id' => 999999, 'user_secret' => 'fake-user-secret-not-real'];
        $data = $this->client($this->spxAccount())->post('/open/api/v1/account/verify', $body);

        $this->assertSame(['match_result' => true], $data);

        Http::assertSentCount(1);
        Http::assertSent(function ($request) use ($body) {
            $expectedPayload = json_encode($body);

            if ($request->body() !== $expectedPayload) {
                return false;
            }

            $appId = (int) $request->header('app-id')[0];
            $timestamp = (int) $request->header('timestamp')[0];
            $randomNum = (int) $request->header('random-num')[0];
            $checkSign = $request->header('check-sign')[0];

            $expectedSign = SpxClient::sign($appId, $timestamp, $randomNum, $expectedPayload, 'fake-app-secret-not-real');

            return $appId === 424242
                && $checkSign === $expectedSign
                && $request->url() === 'https://test-stable.spx.ph/open/api/v1/account/verify'
                && str_contains($request->header('Content-Type')[0] ?? '', 'application/json');
        });
    }

    public function test_post_unwraps_the_envelope_and_returns_only_data_on_success(): void
    {
        Http::fake([
            '*' => Http::response(['ret_code' => 0, 'message' => 'success.', 'data' => ['foo' => 'bar']], 200),
        ]);

        $data = $this->client($this->spxAccount())->post('/open/api/v1/order/get_pickup_time', ['user_id' => 1, 'user_secret' => 'x', 'service_type' => 1]);

        $this->assertSame(['foo' => 'bar'], $data);
    }

    public function test_post_throws_a_typed_spx_api_exception_carrying_the_ret_code_on_failure(): void
    {
        Http::fake([
            '*' => Http::response(['ret_code' => 1007, 'message' => 'CheckSignVerificationFailedCode', 'data' => null], 200),
        ]);

        try {
            $this->client($this->spxAccount())->post('/open/api/v1/account/verify', ['user_id' => 1, 'user_secret' => 'x']);
            $this->fail('Expected SpxApiException was not thrown.');
        } catch (SpxApiException $e) {
            $this->assertSame(1007, $e->retCode);
            $this->assertSame('CheckSignVerificationFailedCode', $e->getMessage());
        }
    }

    public function test_post_throws_a_runtime_exception_on_a_non_envelope_response(): void
    {
        Http::fake(['*' => Http::response('not json at all', 502)]);

        $this->expectException(\RuntimeException::class);
        $this->client($this->spxAccount())->post('/open/api/v1/account/verify', ['user_id' => 1, 'user_secret' => 'x']);
    }

    public function test_get_shipping_label_posts_the_documented_batch_no_shape(): void
    {
        Http::fake([
            '*batch_get_shipping_label*' => Http::response([
                'ret_code' => 0, 'message' => 'success.',
                'data' => ['batch_no' => 207982800897461, 'task_status' => 4, 'awb_link' => 'https://test-stable.spx.co.th/downloads/x.pdf'],
            ]),
        ]);

        $data = $this->client($this->spxAccount())->getShippingLabel(207982800897461);

        $this->assertSame(4, $data['task_status']);
        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return str_contains($request->url(), 'batch_get_shipping_label')
                && $body['batch_no'] === 207982800897461
                && $body['user_id'] === 999999
                && $body['user_secret'] === 'fake-user-secret-not-real';
        });
    }

    // --- Webhook signature verification: same algorithm and test vector as SpxSignerTest. ---

    private function webhookAccount(): CourierAccount
    {
        $account = CourierAccount::factory()->create(['courier' => 'spx']);
        AccountSettings::for($account)->write('spx_sandbox_app_id', '100000');
        AccountSettings::for($account)->writeSecret('spx_sandbox_secret_key', 'H25HY53GO4BA2GQ');

        return $account;
    }

    public function test_verify_webhook_signature_accepts_the_verified_integration_brief_test_vector(): void
    {
        $payload = '{"user_id":239404781503925,"user_secret":"85f0d570-a265-4d9d-857e-b30aa57c4fbe","service_type":1}';
        $expectedSign = '97e21b23940e4ddc96fb4d2474f02425353d2b0e23aee384f3f94c3d0b9ba17d';

        $this->assertTrue($this->client($this->webhookAccount())->verifyWebhookSignature($payload, '100000', $expectedSign, '1677918414', '926611981'));
    }

    public function test_verify_webhook_signature_rejects_a_tampered_check_sign(): void
    {
        $payload = '{"user_id":239404781503925,"user_secret":"85f0d570-a265-4d9d-857e-b30aa57c4fbe","service_type":1}';

        $this->assertFalse($this->client($this->webhookAccount())->verifyWebhookSignature($payload, '100000', str_repeat('a', 64), '1677918414', '926611981'));
    }

    public function test_verify_webhook_signature_rejects_a_tampered_body(): void
    {
        $tamperedPayload = '{"user_id":239404781503925,"user_secret":"85f0d570-a265-4d9d-857e-b30aa57c4fbe","service_type":2}';
        $expectedSign = '97e21b23940e4ddc96fb4d2474f02425353d2b0e23aee384f3f94c3d0b9ba17d'; // signed over service_type:1

        $this->assertFalse($this->client($this->webhookAccount())->verifyWebhookSignature($tamperedPayload, '100000', $expectedSign, '1677918414', '926611981'));
    }

    public function test_verify_webhook_signature_rejects_an_app_id_that_does_not_match_our_own(): void
    {
        $payload = '{"user_id":239404781503925,"user_secret":"85f0d570-a265-4d9d-857e-b30aa57c4fbe","service_type":1}';
        // A VALID signature, but for a different app-id than the account's own: still rejected.
        $spoofedSign = SpxClient::sign(999999, 1677918414, 926611981, $payload, 'H25HY53GO4BA2GQ');

        $this->assertFalse($this->client($this->webhookAccount())->verifyWebhookSignature($payload, '999999', $spoofedSign, '1677918414', '926611981'));
    }

    public function test_verify_webhook_signature_without_an_app_id_header_uses_our_own_app_id(): void
    {
        $payload = '{"user_id":239404781503925,"user_secret":"85f0d570-a265-4d9d-857e-b30aa57c4fbe","service_type":1}';
        $expectedSign = '97e21b23940e4ddc96fb4d2474f02425353d2b0e23aee384f3f94c3d0b9ba17d';
        $client = $this->client($this->webhookAccount());

        $this->assertTrue($client->verifyWebhookSignature($payload, null, $expectedSign, '1677918414', '926611981'));
        $this->assertTrue($client->verifyWebhookSignature($payload, '', $expectedSign, '1677918414', '926611981'));
    }

    public function test_verify_webhook_signature_compares_hex_case_insensitively(): void
    {
        $payload = '{"user_id":239404781503925,"user_secret":"85f0d570-a265-4d9d-857e-b30aa57c4fbe","service_type":1}';

        $this->assertTrue($this->client($this->webhookAccount())->verifyWebhookSignature($payload, '100000', '97E21B23940E4DDC96FB4D2474F02425353D2B0E23AEE384F3F94C3D0B9BA17D', '1677918414', '926611981'));
    }

    public function test_verify_webhook_signature_rejects_missing_headers(): void
    {
        $client = $this->client($this->webhookAccount());
        $payload = '{"foo":"bar"}';

        $this->assertFalse($client->verifyWebhookSignature($payload, null, 'x', '123', '456'));
        $this->assertFalse($client->verifyWebhookSignature($payload, '100000', null, '123', '456'));
        $this->assertFalse($client->verifyWebhookSignature($payload, '100000', 'x', null, '456'));
        $this->assertFalse($client->verifyWebhookSignature($payload, '100000', 'x', '123', null));
    }

    public function test_verify_webhook_signature_rejects_when_app_not_configured(): void
    {
        $client = $this->client(CourierAccount::factory()->create(['courier' => 'spx']));

        $this->assertFalse($client->verifyWebhookSignature('{}', '100000', 'x', '123', '456'));
    }
}
