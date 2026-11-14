<?php

namespace Tests\Feature\Api;

use App\Couriers\CourierRegistry;
use App\Couriers\FakeCourier;
use App\Models\CourierAccount;
use App\Shipments\Quotes\InlineQuoteRunner;
use App\Shipments\Quotes\ProcessQuoteRunner;
use App\Shipments\Quotes\QuoteRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsCourierAccounts;
use Tests\Concerns\CallsClientApi;
use Tests\TestCase;

/**
 * POST /quotes with `couriers: "all"`: every active account at once, one entry each, within a time budget.
 * Couriers are faked in this process (InlineQuoteRunner); ProcessQuoteRunner's own cut-off is tested with stand-in
 * processes that never reach a courier.
 */
class AllCouriersQuoteTest extends TestCase
{
    use BuildsCourierAccounts, CallsClientApi, RefreshDatabase;

    private array $client;

    /** SPX's next 2.1 answer (Http::fake is set once: a second fake of the same URL would be ignored). */
    private array $spxAnswer = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake([
            'api.staging.quadx.xyz/*' => Http::response($this->quadxFixture('rates')),
            '*batch_check_order*' => fn () => Http::response($this->spxAnswer),
        ]);
        $this->client = $this->makeClient();
        app()->instance(QuoteRunner::class, new InlineQuoteRunner);
    }

    private function body(array $over = []): array
    {
        return array_replace_recursive([
            'couriers' => 'all',
            'pickup' => ['contact_name' => 'Juan Shop', 'contact_phone' => '0918 123 4567', 'address_1' => '5 Shaw Blvd',
                'barangay' => 'Wack-wack Greenhills', 'city' => 'Mandaluyong', 'state' => 'Metro Manila', 'postcode' => '1550'],
            'recipient' => ['first_name' => 'Jane', 'last_name' => 'Doe', 'phone' => '09171234567', 'address_1' => '123 Mango St',
                'barangay' => 'Lahug (Pob.)', 'city' => 'Cebu City', 'state' => 'Cebu', 'postcode' => '6000'],
            'parcel' => ['type' => 'box', 'weight_kg' => 1, 'length_cm' => 20, 'width_cm' => 15, 'height_cm' => 10],
            'payment' => ['method' => 'prepaid'],
        ], $over);
    }

    private function all(array $over = []): TestResponse
    {
        return $this->apiJson('POST', '/api/v1/quotes', $this->body($over), [], $this->client);
    }

    private function spxFee(int $fee): void
    {
        $this->spxAnswer = ['ret_code' => 0, 'message' => 'success.', 'data' => [
            'orders' => [['order_id' => '1', 'estimated_shipping_fee' => $fee, 'remote_area_fee' => 0]], 'fail_list' => [],
        ]];
    }

    private function spxRefuses(): void
    {
        $this->spxAnswer = ['ret_code' => 0, 'message' => 'success.', 'data' => ['orders' => [], 'fail_list' => [[
            'order_id' => '1', 'ret_code' => 13051, 'message' => 'check rule|7 |ruleName| MaxLengthExtremumRule failed, 35.000000 exceed max value 25.000000',
        ]]]];
    }

    /** @return array{0: CourierAccount, 1: CourierAccount, 2: CourierAccount} quadx, spx, lbc (a fake with no figure) */
    private function accounts(): array
    {
        $quadx = $this->quadxAccount();
        $spx = $this->spxAccount();
        CourierRegistry::fake('lbc', FakeCourier::make('lbc'));
        $lbc = CourierAccount::factory()->create(['courier' => 'lbc', 'status' => 'active']);

        return [$quadx, $spx, $lbc];
    }

    public function test_every_active_account_answers_in_a_stable_order(): void
    {
        [$quadx, $spx, $lbc] = $this->accounts();
        $this->spxFee(195);
        CourierAccount::factory()->create(['courier' => 'quadx', 'status' => 'disabled']);

        $data = $this->all()->assertOk()->json('data');

        $this->assertSame(['lbc', 'quadx', 'spx'], array_column($data, 'courier'));
        $this->assertSame([$lbc->public_id, $quadx->public_id, $spx->public_id], array_column($data, 'account_id'), 'inactive accounts are left out');
        foreach ($data as $entry) {
            $this->assertSame(['courier', 'account_id', 'service', 'amount', 'currency', 'label', 'odz', 'remote_area_fee', 'serviceable', 'code', 'reason'], array_keys($entry));
        }

        $this->assertSame(['service' => null, 'amount' => '195.00', 'currency' => 'PHP', 'serviceable' => true, 'code' => null],
            array_intersect_key($data[2], array_flip(['amount', 'currency', 'serviceable', 'code', 'service'])));
        $this->assertSame('PHP', $data[1]['currency']);
        $this->assertTrue($data[1]['serviceable']);
        $this->assertNotNull($data[1]['amount']);
        $this->assertSame('next_day', $data[1]['service']);

        // LBC answered without a figure: no fee, never an invented one.
        $this->assertSame(['amount' => null, 'serviceable' => null, 'code' => 'no_fee', 'reason' => null],
            array_intersect_key($data[0], array_flip(['amount', 'serviceable', 'code', 'reason'])));
    }

    public function test_a_spx_refusal_is_an_entry_with_its_code_and_reason(): void
    {
        $this->accounts();
        $this->spxRefuses();

        $spx = collect($this->all(['parcel' => ['length_cm' => 35]])->assertOk()->json('data'))->firstWhere('courier', 'spx');

        $this->assertNull($spx['amount']);
        $this->assertFalse($spx['serviceable']);
        $this->assertSame('parcel_too_long', $spx['code']);
        $this->assertSame('Too long for SPX. Length is 35 cm. SPX takes up to 25 cm.', $spx['reason']);
    }

    public function test_a_courier_past_the_budget_is_a_timeout_entry(): void
    {
        [, $spx] = $this->accounts();
        $this->spxFee(195);
        // The runner gives up on SPX as the process runner does at the deadline.
        app()->instance(QuoteRunner::class, new class($spx->public_id) implements QuoteRunner
        {
            public function __construct(private string $slow) {}

            public function run(array $jobs, float $budgetSeconds): array
            {
                $results = (new InlineQuoteRunner)->run(array_diff_key($jobs, [$this->slow => 1]), $budgetSeconds);

                return $results + [$this->slow => ['status' => 'timeout']];
            }
        });

        $data = collect($this->all(['budget_ms' => 1500])->assertOk()->json('data'))->keyBy('courier');

        $this->assertSame(['amount' => null, 'serviceable' => null, 'code' => 'timeout'],
            array_intersect_key($data['spx'], array_flip(['amount', 'serviceable', 'code'])));
        $this->assertTrue($data['quadx']['serviceable']);
    }

    public function test_the_process_runner_cuts_a_slow_courier_off_at_the_budget(): void
    {
        $php = PHP_BINARY;
        $runner = new ProcessQuoteRunner(fn (string $key) => match ($key) {
            'fast' => [$php, '-r', 'echo json_encode(["fee" => ["amount" => "99.00"], "refusal" => null]);'],
            'broken' => [$php, '-r', 'exit(1);'],
            default => [$php, '-r', 'sleep(10);'],
        });
        $jobs = ['fast' => ['account_id' => 'a', 'data' => []], 'broken' => ['account_id' => 'b', 'data' => []], 'slow' => ['account_id' => 'c', 'data' => []]];

        $started = microtime(true);
        $results = $runner->run($jobs, 1.2);
        $elapsed = microtime(true) - $started;

        $this->assertLessThan(2.0, $elapsed, 'the slow process does not hold the answer');
        $this->assertSame(['status' => 'done', 'answer' => ['fee' => ['amount' => '99.00'], 'refusal' => null]], $results['fast']);
        $this->assertSame(['status' => 'failed'], $results['broken']);
        $this->assertSame(['status' => 'timeout'], $results['slow']);
    }

    public function test_the_switch_is_validated_and_never_mixed_with_one_account(): void
    {
        [$quadx] = $this->accounts();

        $this->all(['couriers' => 'some'])->assertStatus(422)->assertJsonPath('code', 'invalid_request');
        $this->all(['account_id' => $quadx->public_id])->assertStatus(422);
        $this->all(['budget_ms' => 500])->assertStatus(422);
        $this->all(['budget_ms' => 9000])->assertStatus(422);
        $this->all(['budget_ms' => 8000])->assertOk();

        // Without the switch, an account or courier is still required, as before.
        $body = $this->body();
        unset($body['couriers']);
        $this->apiJson('POST', '/api/v1/quotes', $body, [], $this->client)->assertStatus(422);
    }

    public function test_one_account_answers_exactly_as_before(): void
    {
        [$quadx, $spx] = $this->accounts();
        $this->spxFee(195);
        $body = $this->body();
        unset($body['couriers']);

        $this->apiJson('POST', '/api/v1/quotes', ['account_id' => $spx->public_id, 'parcel' => ['type' => 'small']] + $body, [], $this->client)
            ->assertOk()
            ->assertExactJson(['data' => ['amount' => '195.00', 'currency' => 'PHP', 'label' => '₱195.00', 'odz' => false, 'remote_area_fee' => '0.00']]);
    }

    public function test_it_counts_once_against_the_courier_lookup_limit(): void
    {
        $this->accounts();
        $this->spxFee(195);
        $tokenId = $this->client['app']->tokens()->value('id');

        $this->all()->assertOk();

        $this->assertSame(1, RateLimiter::attempts(md5('api-coverage'.'token:'.$tokenId)));
    }
}
