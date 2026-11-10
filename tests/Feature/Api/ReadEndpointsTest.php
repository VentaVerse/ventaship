<?php

namespace Tests\Feature\Api;

use App\Couriers\AccountSettings;
use App\Couriers\CourierOrder;
use App\Couriers\CourierRegistry;
use App\Couriers\FakeCourier;
use App\Couriers\PickupPoint;
use App\Couriers\Support\ServiceabilityVerdict;
use App\Models\CourierAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsCourierAccounts;
use Tests\Concerns\BuildsShipments;
use Tests\Concerns\CallsClientApi;
use Tests\TestCase;

class ReadEndpointsTest extends TestCase
{
    use BuildsCourierAccounts, BuildsShipments, RefreshDatabase, CallsClientApi;

    private array $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = $this->makeClient();
    }

    public function test_couriers_lists_capabilities(): void
    {
        $res = $this->api('GET', '/api/v1/couriers')->assertOk();

        $byKey = collect($res->json('data'))->keyBy('key');
        $this->assertSame(['lbc', 'quadx', 'spx'], $byKey->keys()->sort()->values()->all());

        $this->assertTrue($byKey['quadx']['cancel']);
        $this->assertFalse($byKey['quadx']['poll_only']);
        $this->assertTrue($byKey['quadx']['webhooks']);
        $this->assertFalse($byKey['lbc']['cancel']);
        $this->assertTrue($byKey['lbc']['poll_only']);
        $this->assertFalse($byKey['lbc']['webhooks']);
        $this->assertTrue($byKey['spx']['pickup_slots']);

        foreach (['name', 'methods', 'quotes', 'coverage', 'label_formats', 'service_types', 'parcel_options', 'shipping_payment_options'] as $field) {
            $this->assertArrayHasKey($field, $byKey['quadx'], $field);
        }
        $this->assertNotEmpty($byKey['quadx']['service_types']);
    }

    public function test_courier_accounts_never_expose_secrets(): void
    {
        $account = $this->quadxAccount('qx-api-key-SECRETVALUE1', 'qx-secret-SECRETVALUE2', [
            'is_default' => true, 'default_slot' => 'quadx', 'label' => 'Main QuadX',
            'webhook_token' => 'whk-SECRETVALUE3', 'relay_secret' => 'relay-SECRETVALUE4',
        ]);
        $spx = CourierAccount::factory()->create(['courier' => 'spx', 'label' => 'SPX without keys']);

        $list = $this->api('GET', '/api/v1/courier-accounts')->assertOk();
        $one = $this->api('GET', "/api/v1/courier-accounts/{$account->public_id}")->assertOk();

        $one->assertJsonPath('data.id', $account->public_id)
            ->assertJsonPath('data.courier', 'quadx')
            ->assertJsonPath('data.label', 'Main QuadX')
            ->assertJsonPath('data.mode', 'sandbox')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.is_default', true)
            ->assertJsonPath('data.issues', [])
            ->assertJsonPath('data.capabilities.cancel', true);

        $spxRow = collect($list->json('data'))->firstWhere('id', $spx->public_id);
        $this->assertNotEmpty($spxRow['issues']);

        foreach ([$list, $one] as $res) {
            $json = $res->getContent();
            $this->assertStringNotContainsString('SECRETVALUE', $json);
            $this->assertNoSecretKeys($res->json());
        }
    }

    public function test_unknown_courier_account_is_404(): void
    {
        $this->api('GET', '/api/v1/courier-accounts/acc_nope')->assertNotFound()->assertJsonPath('code', 'not_found');
    }

    public function test_coverage_uses_cache(): void
    {
        Http::fake(['api.staging.quadx.xyz/*' => Http::response($this->quadxFixture('rates'))]);
        $account = $this->quadxAccount();
        $body = $this->coverageBody(['account_id' => $account->public_id]);

        $first = $this->api('POST', '/api/v1/coverage', $body)->assertOk();
        $second = $this->api('POST', '/api/v1/coverage', $body)->assertOk();

        $first->assertJsonPath('data.0.ref', 'a')->assertJsonPath('data.0.verdict', 'serviceable');
        $this->assertSame($first->json('data'), $second->json('data'));
        Http::assertSentCount(1);
    }

    public function test_coverage_cache_follows_the_default_service(): void
    {
        Http::fake(['api.staging.quadx.xyz/*' => Http::response($this->quadxFixture('rates'))]);
        $account = $this->quadxAccount();
        $body = $this->coverageBody(['account_id' => $account->public_id]);

        $this->api('POST', '/api/v1/coverage', $body)->assertOk();
        AccountSettings::for($account)->write('quadx_default_service', 'same_day');
        $this->api('POST', '/api/v1/coverage', $body)->assertOk();

        Http::assertSentCount(2);
    }

    public function test_coverage_validates_destinations(): void
    {
        $this->fakeDefaultAccount();
        $body = $this->coverageBody();
        $body['destinations'] = array_fill(0, 11, $body['destinations'][0]);

        $this->api('POST', '/api/v1/coverage', $body)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['destinations'], 'errors');
    }

    public function test_coverage_by_default_courier_account(): void
    {
        $this->fakeDefaultAccount();

        $this->api('POST', '/api/v1/coverage', $this->coverageBody())
            ->assertOk()
            ->assertJsonPath('data.0.verdict', 'serviceable')
            ->assertJsonPath('data.0.reason', null);
    }

    public function test_quotes_answers_null_when_the_courier_has_no_figure(): void
    {
        $account = $this->fakeDefaultAccount();
        $body = $this->shipmentBody(['account_id' => $account->public_id]);

        $this->api('POST', '/api/v1/quotes', [
            'account_id' => $account->public_id, 'pickup' => $body['pickup'], 'recipient' => $body['recipient'],
            'parcel' => $body['parcel'], 'payment' => $body['payment'],
        ])->assertOk()->assertExactJson(['data' => null]);
    }

    public function test_quotes_answers_the_couriers_figure(): void
    {
        Http::fake(['api.staging.quadx.xyz/*' => Http::response($this->quadxFixture('rates'))]);
        $account = $this->quadxAccount();
        $body = $this->shipmentBody();

        $this->api('POST', '/api/v1/quotes', [
            'account_id' => $account->public_id, 'pickup' => $body['pickup'], 'recipient' => $body['recipient'],
            'parcel' => $body['parcel'], 'payment' => $body['payment'],
        ])->assertOk()
            ->assertJsonPath('data.currency', 'PHP')
            ->assertJsonStructure(['data' => ['amount', 'currency', 'label']]);
    }

    public function test_quotes_and_coverage_carry_odz_and_remote_area_fee_from_the_courier(): void
    {
        $account = CourierAccount::factory()->create(['courier' => 'spx', 'is_default' => true, 'default_slot' => 'spx']);
        CourierRegistry::fake('spx', new class extends FakeCourier
        {
            public function estimateFee(CourierOrder $order, array $options): ?object
            {
                return (object) ['amount' => 80.0, 'currency' => 'PHP', 'label' => '₱80.00', 'remote_area_fee' => '25.00', 'odz' => true];
            }

            public function checkServiceability(CourierOrder $order, PickupPoint $from): ServiceabilityVerdict
            {
                return ServiceabilityVerdict::serviceable(['estimate' => 80.0])->withOdz(true);
            }
        });
        $body = $this->shipmentBody();

        $this->api('POST', '/api/v1/quotes', [
            'account_id' => $account->public_id, 'pickup' => $body['pickup'], 'recipient' => $body['recipient'],
            'parcel' => $body['parcel'], 'payment' => $body['payment'],
        ])->assertOk()->assertExactJson(['data' => [
            'amount' => '80.00', 'currency' => 'PHP', 'label' => '₱80.00', 'odz' => true, 'remote_area_fee' => '25.00',
        ]]);

        $this->api('POST', '/api/v1/coverage', $this->coverageBody(['courier' => 'spx']))
            ->assertOk()->assertJsonPath('data.0.verdict', 'serviceable')->assertJsonPath('data.0.odz', true);
    }

    public function test_odz_is_null_when_the_courier_gives_no_signal(): void
    {
        Http::fake(['api.staging.quadx.xyz/*' => Http::response($this->quadxFixture('rates'))]);
        $account = $this->quadxAccount();
        $body = $this->shipmentBody();

        $quote = $this->api('POST', '/api/v1/quotes', [
            'account_id' => $account->public_id, 'pickup' => $body['pickup'], 'recipient' => $body['recipient'],
            'parcel' => $body['parcel'], 'payment' => $body['payment'],
        ])->assertOk();
        $this->assertNull($quote->json('data.odz'));
        $this->assertNull($quote->json('data.remote_area_fee'));
        $this->assertArrayHasKey('odz', $quote->json('data'));

        $coverage = $this->api('POST', '/api/v1/coverage', $this->coverageBody(['account_id' => $account->public_id]))->assertOk();
        $this->assertArrayHasKey('odz', $coverage->json('data.0'));
        $this->assertNull($coverage->json('data.0.odz'));
    }

    public function test_pickup_slots(): void
    {
        $lbc = CourierAccount::factory()->create(['courier' => 'lbc']);

        $res = $this->api('POST', '/api/v1/pickup-slots', [
            'account_id' => $lbc->public_id, 'pickup' => $this->shipmentBody()['pickup'],
        ])->assertOk();

        $this->assertNotEmpty($res->json('data'));
        $this->assertSame(['value', 'label', 'date'], array_keys($res->json('data.0')));
        $this->assertSame($res->json('data.0.value'), $res->json('data.0.date'));
    }

    public function test_coverage_is_rate_limited_per_key(): void
    {
        $this->fakeDefaultAccount();
        $tokenId = $this->client['app']->tokens()->value('id');
        for ($i = 0; $i < 60; $i++) {
            RateLimiter::hit(md5('api-coverage'.'token:'.$tokenId), 60);
        }

        $res = $this->api('POST', '/api/v1/coverage', $this->coverageBody())
            ->assertStatus(429)
            ->assertJsonPath('code', 'rate_limited')
            ->assertJsonPath('retryable', true);
        $this->assertNotNull($res->headers->get('Retry-After'));

        // Another token has its own budget.
        $this->client = $this->makeClient();
        $this->api('POST', '/api/v1/coverage', $this->coverageBody())->assertOk();
    }

    public function test_refused_calls_do_not_spend_a_budget(): void
    {
        $tokenId = $this->client['app']->tokens()->value('id');
        [, $secret] = explode('|', $this->client['key'], 2);

        $this->call('GET', '/api/v1/couriers', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$tokenId.'|'.strrev($secret)])
            ->assertStatus(401);
        $this->call('GET', '/api/v1/couriers')->assertStatus(401);

        // The limiter never ran: the 401 came first.
        $this->assertSame(0, RateLimiter::attempts(md5('api'.'token:'.$tokenId)));
        $this->assertSame(0, RateLimiter::attempts(md5('api'.'ip:127.0.0.1')));

        $this->call('GET', '/api/v1/couriers', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->client['key']])->assertOk();
        $this->assertSame(1, RateLimiter::attempts(md5('api'.'token:'.$tokenId)));
    }

    private function coverageBody(array $overrides = []): array
    {
        $body = $this->shipmentBody();

        return $overrides + [
            'courier' => 'quadx',
            'pickup' => $body['pickup'],
            'destinations' => [['ref' => 'a', 'recipient' => $body['recipient']]],
            'payment' => ['method' => 'prepaid'],
        ];
    }

    private function assertNoSecretKeys(mixed $node, string $path = ''): void
    {
        if (! is_array($node)) {
            return;
        }
        foreach ($node as $key => $value) {
            if (is_string($key)) {
                $this->assertDoesNotMatchRegularExpression('/secret|token|api_key|password|(^|_)key$/i', $key, "{$path}.{$key}");
            }
            $this->assertNoSecretKeys($value, "{$path}.{$key}");
        }
    }

    private function api(string $method, string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->apiJson($method, $uri, $data, $headers, $this->client);
    }
}
