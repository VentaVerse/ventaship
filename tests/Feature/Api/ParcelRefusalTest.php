<?php

namespace Tests\Feature\Api;

use App\Models\CourierAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsCourierAccounts;
use Tests\Concerns\CallsClientApi;
use Tests\TestCase;

/**
 * POST /coverage with a described `parcel`, and POST /quotes, when SPX refuses the parcel for its size or weight:
 * SPX's real answer (2.1 Check Shipping Fee) is faked here, in the shape the sandbox sends it.
 */
class ParcelRefusalTest extends TestCase
{
    use BuildsCourierAccounts, CallsClientApi, RefreshDatabase;

    private array $client;

    private CourierAccount $account;

    /** SPX's next 2.1 answer. Http::fake is set once: a second fake of the same URL would be ignored. */
    private array $spxAnswer = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake(['*batch_check_order*' => fn () => Http::response($this->spxAnswer)]);
        $this->client = $this->makeClient();
        $this->account = $this->spxAccount(['is_default' => true, 'default_slot' => 'spx']);
    }

    private function api(string $uri, array $data): TestResponse
    {
        return $this->apiJson('POST', $uri, $data, [], $this->client);
    }

    /** @return array<string, mixed> */
    private function pickup(): array
    {
        return ['contact_name' => 'Juan Shop', 'contact_phone' => '0918 123 4567', 'address_1' => '5 Shaw Blvd',
            'barangay' => 'Wack-wack Greenhills', 'city' => 'Mandaluyong', 'state' => 'Metro Manila', 'postcode' => '1550'];
    }

    /** @return array<string, mixed> */
    private function recipient(): array
    {
        return ['first_name' => 'Jane', 'last_name' => 'Doe', 'phone' => '09171234567', 'address_1' => '123 Mango St',
            'barangay' => 'Lahug (Pob.)', 'city' => 'Cebu City', 'state' => 'Cebu', 'postcode' => '6000'];
    }

    private function coverage(array $extra = []): TestResponse
    {
        return $this->api('/api/v1/coverage', $extra + [
            'account_id' => $this->account->public_id,
            'pickup' => $this->pickup(),
            'destinations' => [['ref' => 'a', 'recipient' => $this->recipient()]],
        ]);
    }

    private function refuses(string $message, int $code = 13051): void
    {
        $this->spxAnswer = ['ret_code' => 0, 'message' => 'success.', 'data' => [
            'orders' => [], 'fail_list' => [['order_id' => '1', 'ret_code' => $code, 'message' => $message, 'debug_msg' => 'order {"order_id":"1"}']],
        ]];
    }

    private function prices(int $fee = 195): void
    {
        $this->spxAnswer = ['ret_code' => 0, 'message' => 'success.', 'data' => [
            'orders' => [['order_id' => '1', 'estimated_shipping_fee' => $fee, 'remote_area_fee' => 0]], 'fail_list' => [],
        ]];
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string, 2: int, 3: string, 4: string}> */
    public static function refusals(): array
    {
        return [
            'too long' => [['length_cm' => 35, 'width_cm' => 20, 'height_cm' => 10, 'weight_kg' => 1],
                'check rule|7 |ruleName| MaxLengthExtremumRule failed, 35.000000 exceed max value 25.000000', 13051,
                'parcel_too_long', 'Too long for SPX. Length is 35 cm. SPX takes up to 25 cm.'],
            'too wide' => [['length_cm' => 20, 'width_cm' => 60, 'height_cm' => 10, 'weight_kg' => 1],
                'check rule|8 |ruleName| MaxWidthExtremumRule failed, 60.000000 exceed max value 45.000000', 13051,
                'parcel_too_wide', 'Too wide for SPX. Width is 60 cm. SPX takes up to 45 cm.'],
            'too tall' => [['length_cm' => 20, 'width_cm' => 15, 'height_cm' => 60, 'weight_kg' => 1],
                'check rule|9 |ruleName| HeightSumExtremumRule failed, 60.000000 exceed max value 30.000000', 13051,
                'parcel_too_tall', 'Too tall for SPX. Height is 60 cm. SPX takes up to 30 cm.'],
            'too heavy' => [['length_cm' => 20, 'width_cm' => 15, 'height_cm' => 10, 'weight_kg' => 12],
                'parcel weight should less than or equal to 10 kg', 13051,
                'parcel_too_heavy', 'Too heavy for SPX. Weight is 12 kg. SPX takes up to 10 kg.'],
            'outer code 10084' => [['length_cm' => 35, 'width_cm' => 20, 'height_cm' => 10, 'weight_kg' => 1],
                'Parcel info error, length exceed max value.', 10084,
                'parcel_too_long', 'Too long for SPX. Length is 35 cm.'],
        ];
    }

    #[DataProvider('refusals')]
    public function test_coverage_refuses_a_parcel_spx_will_not_take(array $parcel, string $said, int $code, string $expectedCode, string $reason): void
    {
        $this->refuses($said, $code);

        $this->coverage(['parcel' => $parcel])->assertOk()
            ->assertJsonPath('data.0.ref', 'a')
            ->assertJsonPath('data.0.verdict', 'not_serviceable')
            ->assertJsonPath('data.0.kind', 'parcel')
            ->assertJsonPath('data.0.code', $expectedCode)
            ->assertJsonPath('data.0.reason', $reason);

        // SPX was asked about the parcel as described.
        Http::assertSent(function (HttpRequest $r) use ($parcel) {
            $p = $r['orders'][0]['parcel_info'];

            return (float) $p['parcel_length'] === (float) $parcel['length_cm']
                && (float) $p['parcel_width'] === (float) $parcel['width_cm']
                && (float) $p['parcel_height'] === (float) $parcel['height_cm']
                && (float) $p['parcel_weight'] === (float) $parcel['weight_kg'];
        });
    }

    public function test_coverage_without_a_parcel_is_unchanged(): void
    {
        // The Small preset and its weight, and SPX's own words for a parcel complaint, as before.
        $this->refuses('MaxLengthExtremumRule failed, 35.000000 exceed max value 25.000000');

        $this->coverage()->assertOk()
            ->assertJsonPath('data.0.verdict', 'unknown')
            ->assertJsonPath('data.0.kind', null)
            ->assertJsonPath('data.0.code', null)
            ->assertJsonPath('data.0.reason', 'MaxLengthExtremumRule failed, 35.000000 exceed max value 25.000000');

        Http::assertSent(function (HttpRequest $r) {
            $p = $r['orders'][0]['parcel_info'];

            return [(float) $p['parcel_length'], (float) $p['parcel_width'], (float) $p['parcel_height'], (float) $p['parcel_weight']] === [25.0, 20.0, 10.0, 3.0];
        });
    }

    public function test_coverage_answers_are_cached_per_parcel(): void
    {
        $this->prices();
        $small = ['length_cm' => 20, 'width_cm' => 15, 'height_cm' => 10, 'weight_kg' => 1];

        $this->coverage(['parcel' => $small])->assertJsonPath('data.0.verdict', 'serviceable');
        $this->coverage(['parcel' => $small])->assertJsonPath('data.0.verdict', 'serviceable');
        Http::assertSentCount(1);

        $this->refuses('parcel weight should less than or equal to 10 kg');
        $this->coverage(['parcel' => ['weight_kg' => 12] + $small])->assertJsonPath('data.0.code', 'parcel_too_heavy');
    }

    public function test_other_refusals_keep_their_codes_with_a_parcel(): void
    {
        $this->refuses('Sorry, the delivery address you selected is not supported by our service at the moment', 10019);

        $this->coverage(['parcel' => ['weight_kg' => 1]])->assertOk()
            ->assertJsonPath('data.0.verdict', 'not_serviceable')
            ->assertJsonPath('data.0.kind', 'destination')
            ->assertJsonPath('data.0.code', 'spx_no_delivery');
    }

    public function test_coverage_validates_the_parcel(): void
    {
        $this->coverage(['parcel' => ['weight_kg' => -1, 'length_cm' => 'x']])->assertStatus(422);
    }

    private function quote(array $parcel): TestResponse
    {
        return $this->api('/api/v1/quotes', [
            'account_id' => $this->account->public_id,
            'pickup' => $this->pickup(),
            'recipient' => $this->recipient(),
            'parcel' => $parcel,
            'payment' => ['method' => 'prepaid'],
        ]);
    }

    public function test_quotes_answer_a_refusal_beside_data_null(): void
    {
        $this->refuses('check rule|7 |ruleName| MaxLengthExtremumRule failed, 35.000000 exceed max value 25.000000');

        $this->quote(['type' => 'custom', 'length_cm' => 35, 'width_cm' => 20, 'height_cm' => 10, 'weight_kg' => 1])
            ->assertOk()
            ->assertExactJson(['data' => null, 'refusal' => [
                'code' => 'parcel_too_long',
                'reason' => 'Too long for SPX. Length is 35 cm. SPX takes up to 25 cm.',
            ]]);
    }

    public function test_quotes_refusal_for_a_destination_uses_the_coverage_code(): void
    {
        $this->refuses('Sorry, the delivery address you selected is not supported by our service at the moment', 10019);

        $this->quote(['type' => 'small', 'weight_kg' => 1])->assertOk()
            ->assertJsonPath('data', null)
            ->assertJsonPath('refusal.code', 'spx_no_delivery')
            ->assertJsonPath('refusal.reason', "SPX doesn't deliver here. Check the delivery address.");
    }

    public function test_quotes_success_and_no_figure_are_unchanged(): void
    {
        $this->prices(195);
        $this->quote(['type' => 'small', 'weight_kg' => 1])->assertOk()
            ->assertExactJson(['data' => ['amount' => '195.00', 'currency' => 'PHP', 'label' => '₱195.00', 'odz' => false, 'remote_area_fee' => '0.00']]);

        // An SPX outage is no figure, not a refusal.
        $this->spxAnswer = ['ret_code' => 99001, 'message' => 'Internal error', 'data' => null];
        $this->quote(['type' => 'small', 'weight_kg' => 1])->assertOk()->assertExactJson(['data' => null]);
    }

    public function test_without_the_phones_spx_cannot_be_asked(): void
    {
        $pickup = $this->pickup();
        unset($pickup['contact_phone']);

        $this->coverage(['pickup' => $pickup, 'parcel' => ['weight_kg' => 12]])->assertOk()
            ->assertJsonPath('data.0.verdict', 'unknown')
            ->assertJsonPath('data.0.code', 'missing_pickup_phone');
        Http::assertNothingSent();
    }
}
