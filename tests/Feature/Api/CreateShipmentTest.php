<?php

namespace Tests\Feature\Api;

use App\Models\CourierAccount;
use App\Models\Shipment;
use App\Shipments\Jobs\BookShipment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsShipments;
use Tests\Concerns\CallsClientApi;
use Tests\TestCase;

class CreateShipmentTest extends TestCase
{
    use BuildsShipments, RefreshDatabase, CallsClientApi;

    private array $client;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->client = $this->makeClient();
    }

    public function test_create_returns_202_queued_and_dispatches_booking(): void
    {
        $account = $this->fakeDefaultAccount();

        $res = $this->create($this->shipmentBody());

        $res->assertStatus(202)
            ->assertJsonPath('data.state', 'queued')
            ->assertJsonPath('data.client_reference', '4211')
            ->assertJsonPath('data.order_label', '#4211')
            ->assertJsonPath('data.source', 'api')
            ->assertJsonPath('data.account.id', $account->public_id)
            ->assertJsonPath('data.account.courier', 'quadx')
            ->assertJsonPath('data.payment_method', 'cod')
            ->assertJsonPath('data.payment_state', 'cod_unpaid')
            ->assertJsonPath('data.cod_amount', '1290.00')
            ->assertJsonPath('data.items_summary', 'Blue Tee ×2, Cap ×1')
            ->assertJsonPath('data.metadata.shop_id', '501')
            ->assertJsonMissingPath('data.recipient')
            ->assertJsonMissingPath('data.pickup');
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+08:00$/', $res->json('data.created_at'));

        $s = Shipment::query()->sole();
        $this->assertSame(sprintf('VS%06d', $s->id), $s->courier_ref);
        $this->assertSame('4211', $s->active_reference);
        $this->assertSame(3, $s->items_count);
        $this->assertSame($this->client['app']->id, $s->client_app_id);
        $this->assertSame('Call before delivery', $s->courier_note);

        Queue::assertPushedOn('couriers', BookShipment::class, function (BookShipment $job) use ($s) {
            return $job->shipmentId === $s->id && $job->connection === 'couriers';
        });
    }

    public function test_submit_false_creates_draft_without_job(): void
    {
        $this->fakeDefaultAccount();

        $this->create($this->shipmentBody(['submit' => false]))
            ->assertStatus(201)
            ->assertJsonPath('data.state', 'draft');

        $this->assertSame('draft', Shipment::query()->sole()->state);
        Queue::assertNothingPushed();
    }

    public function test_submit_queues_a_draft_once(): void
    {
        $this->fakeDefaultAccount();
        $id = $this->create($this->shipmentBody(['submit' => false]))->json('data.id');

        $this->api('POST', "/api/v1/shipments/{$id}/submit", [], ['Idempotency-Key' => 'submit-1'])
            ->assertStatus(202)
            ->assertJsonPath('data.state', 'queued');
        Queue::assertPushed(BookShipment::class, 1);

        $this->api('POST', "/api/v1/shipments/{$id}/submit", [], ['Idempotency-Key' => 'submit-2'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'not_draft');
        Queue::assertPushed(BookShipment::class, 1);
    }

    public function test_duplicate_live_reference_is_409(): void
    {
        $this->fakeDefaultAccount();
        $first = $this->create($this->shipmentBody())->assertStatus(202);

        $this->create($this->shipmentBody(['note' => 'second try']))
            ->assertStatus(409)
            ->assertJsonPath('code', 'active_shipment_exists')
            ->assertJsonPath('errors.shipment', $first->json('data.id'));

        $this->assertSame(1, Shipment::query()->count());
    }

    public function test_same_reference_in_another_app_is_not_a_duplicate(): void
    {
        $this->fakeDefaultAccount();
        $this->create($this->shipmentBody())->assertStatus(202);

        $this->client = $this->makeClient();
        $this->create($this->shipmentBody())->assertStatus(202);
    }

    public function test_reference_reusable_after_failed_or_cancelled(): void
    {
        $this->fakeDefaultAccount();

        foreach (['failed', 'cancelled'] as $i => $terminal) {
            $id = $this->create($this->shipmentBody(['note' => "try {$i}"]))->assertStatus(202)->json('data.id');
            // As the booking job and the canceller leave it: terminal, reference released.
            Shipment::query()->where('public_id', $id)->update(['state' => $terminal, 'active_reference' => null]);
        }

        // A terminal shipment that still holds its reference does not block it either.
        $id = $this->create($this->shipmentBody(['note' => 'try 2']))->assertStatus(202)->json('data.id');
        Shipment::query()->where('public_id', $id)->update(['state' => 'failed']);

        $this->create($this->shipmentBody(['note' => 'try 3']))->assertStatus(202);

        $this->assertSame(4, Shipment::query()->where('client_reference', '4211')->count());
        $this->assertSame(1, Shipment::query()->where('active_reference', '4211')->count());
    }

    public function test_insure_is_an_optional_boolean_stored_in_options(): void
    {
        $this->fakeDefaultAccount();

        $this->create($this->shipmentBody(['client_reference' => 'a', 'insure' => 'yes']))
            ->assertStatus(422)->assertJsonValidationErrors(['insure'], 'errors');

        $this->create($this->shipmentBody(['client_reference' => 'b', 'insure' => true]))->assertStatus(202)->assertJsonPath('data.insure', true);
        $this->create($this->shipmentBody(['client_reference' => 'c']))->assertStatus(202)->assertJsonPath('data.insure', false);

        $this->assertSame([true, false], Shipment::query()->orderBy('id')->get()->map(fn (Shipment $s) => $s->options['insure'])->all());
    }

    public function test_money_validation(): void
    {
        $this->fakeDefaultAccount();

        $cases = [
            'thousands separator' => ['cod_amount' => '1,290.00'],
            'negative' => ['cod_amount' => -5],
            'zero' => ['cod_amount' => 0],
            'three decimals' => ['cod_amount' => 12.345],
            'missing with cod' => ['cod_amount' => null],
        ];

        foreach ($cases as $label => $payment) {
            $body = $this->shipmentBody(['client_reference' => Str::random(8)]);
            $body['payment'] = array_replace($body['payment'], $payment);
            if ($payment['cod_amount'] === null) {
                unset($body['payment']['cod_amount']);
            }

            $res = $this->create($body);
            $res->assertStatus(422)->assertJsonPath('code', 'invalid_request');
            $this->assertArrayHasKey('payment.cod_amount', $res->json('errors'), $label);
        }

        $body = $this->shipmentBody();
        unset($body['payment']['method']);
        $this->create($body)->assertStatus(422)->assertJsonValidationErrors(['payment.method'], 'errors');

        $this->create($this->shipmentBody(['payment' => ['method' => 'card']]))
            ->assertStatus(422)->assertJsonValidationErrors(['payment.method'], 'errors');

        $this->create($this->shipmentBody(['payment' => ['declared_value' => -1]]))
            ->assertStatus(422)->assertJsonValidationErrors(['payment.declared_value'], 'errors');

        $this->create($this->shipmentBody(['payment' => ['method' => 'prepaid', 'cod_amount' => 100]]))
            ->assertStatus(422)->assertJsonValidationErrors(['payment.cod_amount'], 'errors');

        $this->assertSame(0, Shipment::query()->count());
    }

    public function test_prepaid_shipment_has_no_collection(): void
    {
        $this->fakeDefaultAccount();
        $body = $this->shipmentBody(['payment' => ['method' => 'prepaid']]);
        unset($body['payment']['cod_amount']);

        $this->create($body)->assertStatus(202)
            ->assertJsonPath('data.payment_method', 'prepaid')
            ->assertJsonPath('data.payment_state', 'prepaid')
            ->assertJsonPath('data.cod_amount', null);
    }

    public function test_phone_normalised(): void
    {
        $this->fakeDefaultAccount();

        $this->create($this->shipmentBody([
            'recipient' => ['phone' => '+63 917 123 4567'],
            'pickup' => ['contact_phone' => '639181234567'],
        ]))->assertStatus(202);

        $s = Shipment::query()->sole();
        $this->assertSame('09171234567', $s->recipient['phone']);
        $this->assertSame('09181234567', $s->pickup['contact_phone']);

        $this->create($this->shipmentBody(['client_reference' => 'X1', 'recipient' => ['phone' => '(02) 8123 4567']]))
            ->assertStatus(422)->assertJsonValidationErrors(['recipient.phone'], 'errors');
    }

    public function test_unicode_and_long_address_preserved(): void
    {
        $this->fakeDefaultAccount();
        $address = str_repeat('Ñé', 127).'x'; // 255 characters, 509 bytes

        $id = $this->create($this->shipmentBody([
            'recipient' => ['first_name' => 'Señora José', 'last_name' => 'Ibáñez', 'address_1' => $address],
        ]))->assertStatus(202)->json('data.id');

        $s = Shipment::query()->sole();
        $this->assertSame(255, mb_strlen($s->recipient['address_1']));
        $this->assertSame($address, $s->recipient['address_1']);
        $this->assertSame('Señora José', $s->recipient['first_name']);
        $this->assertSame('Ibáñez', $s->recipient['last_name']);

        $this->api('GET', "/api/v1/shipments/{$id}?include=parties")
            ->assertOk()
            ->assertJsonPath('data.recipient.address_1', $address)
            ->assertJsonPath('data.recipient.first_name', 'Señora José');

        $this->create($this->shipmentBody(['client_reference' => 'X2', 'recipient' => ['address_1' => $address.'y']]))
            ->assertStatus(422)->assertJsonValidationErrors(['recipient.address_1'], 'errors');
    }

    public function test_parties_codes_is_always_a_json_object(): void
    {
        $this->fakeDefaultAccount();
        $id = $this->create($this->shipmentBody())->assertStatus(202)->json('data.id');

        $body = json_decode((string) $this->api('GET', "/api/v1/shipments/{$id}?include=parties")->assertOk()->getContent());

        // recipient sent `codes: []`, pickup sent three codes: both are objects on the wire.
        $this->assertInstanceOf(\stdClass::class, $body->data->recipient->codes);
        $this->assertSame([], get_object_vars($body->data->recipient->codes));
        $this->assertSame('1380500000', $body->data->pickup->codes->city);
    }

    public function test_show_hides_parties_unless_asked(): void
    {
        $this->fakeDefaultAccount();
        $id = $this->create($this->shipmentBody())->json('data.id');

        $this->api('GET', "/api/v1/shipments/{$id}")
            ->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonMissingPath('data.recipient')
            ->assertJsonMissingPath('data.items');
    }

    public function test_other_apps_shipment_is_404(): void
    {
        $theirs = Shipment::factory()->create();

        $this->api('GET', "/api/v1/shipments/{$theirs->public_id}")
            ->assertNotFound()
            ->assertExactJson(['error' => 'Not found', 'code' => 'not_found', 'retryable' => false, 'errors' => []]);

        $this->api('GET', '/api/v1/shipments/shp_doesnotexist')->assertNotFound()->assertJsonPath('code', 'not_found');
        $this->api('GET', '/api/v1/nothing-here')->assertNotFound()->assertJsonPath('code', 'not_found');
        $this->api('DELETE', '/api/v1/couriers')->assertStatus(405)->assertJsonPath('code', 'method_not_allowed');
    }

    public function test_misconfigured_account_is_422(): void
    {
        // A real QuadX account with no credentials: configurationIssues() names the missing keys.
        CourierAccount::factory()->create(['courier' => 'quadx', 'is_default' => true, 'default_slot' => 'quadx']);

        $this->create($this->shipmentBody())
            ->assertStatus(422)
            ->assertJsonPath('code', 'account_misconfigured');

        $this->fakeDefaultAccount('spx', ['status' => 'disabled']);
        $this->create($this->shipmentBody(['courier' => 'spx']))
            ->assertStatus(422)
            ->assertJsonPath('code', 'account_misconfigured');

        $this->create($this->shipmentBody(['courier' => null, 'account_id' => 'acc_unknown']))
            ->assertStatus(422)
            ->assertJsonPath('code', 'account_not_found');

        $this->assertSame(0, Shipment::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_account_id_selects_a_non_default_account(): void
    {
        $this->fakeDefaultAccount();
        $other = CourierAccount::factory()->create(['courier' => 'quadx', 'label' => 'Second']);

        $this->create($this->shipmentBody(['courier' => null, 'account_id' => $other->public_id]))
            ->assertStatus(202)
            ->assertJsonPath('data.account.id', $other->public_id);
    }

    public function test_create_requires_idempotency_key(): void
    {
        $this->fakeDefaultAccount();

        $this->api('POST', '/api/v1/shipments', $this->shipmentBody())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['Idempotency-Key'], 'errors');
    }

    private function create(array $body, ?string $key = null): TestResponse
    {
        return $this->api('POST', '/api/v1/shipments', $body, ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    /** Every call gets its own timestamp, so identical bodies are never a replay. */
    private function api(string $method, string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->apiJson($method, $uri, $data, $headers, $this->client);
    }
}
