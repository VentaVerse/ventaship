<?php

namespace Tests\Feature\Ui;

use App\Couriers\CourierRegistry;
use App\Couriers\FakeCourier;
use App\Models\Admin;
use App\Models\CourierAccount;
use App\Models\Shipment;
use App\Support\PhAddress;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsShipments;
use Tests\TestCase;

class ShipmentFormRedesignTest extends TestCase
{
    use BuildsShipments, RefreshDatabase;

    private CourierAccount $account;

    private FakeCourier $fake;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->account = $this->fakeDefaultAccount();
        $this->fake = FakeCourier::make('quadx');
        CourierRegistry::fake('quadx', $this->fake);
        $this->actingAs(Admin::query()->create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'correct-horse-battery']));
    }

    private function token(string $url = '/shipments/new'): string
    {
        preg_match('/name="form_token" value="([0-9a-f-]{36})"/', $this->get($url)->assertOk()->getContent(), $m);
        $this->assertNotEmpty($m, 'form_token missing');

        return $m[1];
    }

    private function form(array $over = []): array
    {
        return array_replace_recursive([
            'form_token' => $this->token(),
            'account_id' => $this->account->public_id,
            'recipient' => ['first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'phone' => '0917 123 4567',
                'address_1' => '1 Rizal St', 'city' => 'Makati', 'state' => 'Metro Manila', 'postcode' => '1210'],
            'items' => [['name' => 'Blue Tee', 'quantity' => '2', 'price' => '500.10'], ['name' => 'Cap', 'quantity' => '3', 'price' => '99.99']],
            'parcel' => ['weight_kg' => '1.2'],
            'payment' => ['method' => 'prepaid'],
            'pickup' => ['contact_name' => 'Venta Warehouse', 'contact_phone' => '09181234567', 'address_1' => '5 Shaw Blvd',
                'city' => 'Mandaluyong', 'state' => 'Metro Manila'],
        ], $over);
    }

    public function test_cod_amount_derived_from_items_and_tamper_ignored(): void
    {
        $this->fake->booksAs('QX-COD');

        $this->post('/shipments', $this->form(['payment' => ['method' => 'cod', 'cod_amount' => '1.00', 'declared_value' => '99999']]))
            ->assertSessionHasNoErrors();

        // 2 × 500.10 + 3 × 99.99 = 1300.17
        $s = Shipment::query()->sole();
        $this->assertSame('cod', $s->payment_method);
        $this->assertSame('1300.17', $s->cod_amount);
        $this->assertSame('0.00', $s->declared_value);

        $this->post('/shipments', $this->form(['payment' => ['method' => 'prepaid', 'cod_amount' => '500']]))
            ->assertSessionHasNoErrors();
        $this->assertNull(Shipment::query()->latest('id')->first()->cod_amount);
    }

    public function test_declared_value_zero_when_not_insured_and_total_when_insured(): void
    {
        $this->post('/shipments', $this->form(['insure' => '0', 'payment' => ['declared_value' => '5000']]))->assertSessionHasNoErrors();
        $plain = Shipment::query()->sole();
        $this->assertSame('0.00', $plain->declared_value);
        $this->assertFalse($plain->options['insure']);

        $this->post('/shipments', $this->form(['insure' => '1', 'payment' => ['declared_value' => '1']]))->assertSessionHasNoErrors();
        $insured = Shipment::query()->latest('id')->first();
        $this->assertSame('1300.17', $insured->declared_value);
        $this->assertTrue($insured->options['insure']);
    }

    public function test_cod_requires_priced_items(): void
    {
        $this->post('/shipments', $this->form(['payment' => ['method' => 'cod'], 'items' => [['price' => ''], ['price' => '0']]]))
            ->assertSessionHasErrors(['payment.cod_amount' => 'Add item prices.']);
        $this->assertSame(0, Shipment::query()->count());

        // Prepaid with unpriced items is fine.
        $this->post('/shipments', $this->form(['items' => [['price' => ''], ['price' => '']]]))->assertSessionHasNoErrors();
        $this->assertSame(1, Shipment::query()->count());
    }

    public function test_codes_saved_with_names(): void
    {
        $ph = app(PhAddress::class);
        $district = $ph->districts('133900000')[0];
        $brgy = $ph->barangays($district['code'])[0];
        $recipient = [
            'state' => 'Metro Manila', 'city' => 'Manila', 'district' => $district['name'], 'barangay' => $brgy['name'], 'postcode' => '1006',
            'codes' => ['state' => '130000000', 'city' => '133900000', 'barangay' => $brgy['code']],
        ];

        $this->post('/shipments', $this->form(['recipient' => $recipient]))->assertSessionHasNoErrors();

        $saved = Shipment::query()->sole()->recipient;
        $this->assertSame('Manila', $saved['city']);
        $this->assertSame($district['name'], $saved['district']);
        $this->assertSame($brgy['name'], $saved['barangay']);
        $this->assertSame(['state' => '130000000', 'city' => '133900000', 'barangay' => $brgy['code']], $saved['codes']);

        // A name that does not belong to its code is refused.
        $this->post('/shipments', $this->form(['recipient' => ['city' => 'Makati'] + $recipient]))
            ->assertSessionHasErrors(['recipient.state' => 'Choose from the list.']);
        $this->post('/shipments', $this->form(['pickup' => ['codes' => ['state' => 'bogus']]]))
            ->assertSessionHasErrors(['pickup.state' => 'Choose from the list.']);
        $this->assertSame(1, Shipment::query()->count());
    }

    public function test_array_codes_are_a_validation_error_not_a_crash(): void
    {
        $this->post('/shipments', $this->form(['recipient' => ['codes' => ['state' => ['x']]]]))
            ->assertRedirect()->assertSessionHasErrors('recipient.state');
        $this->assertSame(0, Shipment::query()->count());
    }

    public function test_unmatched_district_of_a_districted_city_errors_on_the_district(): void
    {
        $manila = ['state' => 'Metro Manila', 'city' => 'Manila', 'district' => 'Atlantis', 'postcode' => '1006',
            'codes' => ['state' => '130000000', 'city' => '133900000']];

        $this->post('/shipments', $this->form(['recipient' => $manila]))
            ->assertSessionHasErrors(['recipient.district' => 'Choose from the list.'])
            ->assertSessionDoesntHaveErrors('recipient.state');
        $this->assertSame(0, Shipment::query()->count());
    }

    public function test_legacy_shipment_without_codes_edits(): void
    {
        $s = Shipment::factory()->create([
            'source' => 'manual', 'state' => 'failed', 'client_app_id' => null, 'courier_account_id' => $this->account->id,
            'client_reference' => 'OLD-1', 'active_reference' => 'OLD-1',
            'recipient' => ['first_name' => 'Lola', 'phone' => '09171234567', 'address_1' => '9 Old Rd', 'barangay' => 'Somewhere Old',
                'city' => 'Atlantis', 'state' => 'Lost Province', 'postcode' => '9999', 'country' => 'PH'],
        ]);

        $html = $this->get(route('shipments.edit', $s->public_id))->assertOk()->getContent();
        $this->assertStringContainsString('value="9 Old Rd"', $html);
        $this->assertStringContainsString('value="Atlantis"', $html);
        $this->assertStringContainsString('value="Lost Province"', $html);

        $this->put(route('shipments.update', $s->public_id), $this->form([
            'form_token' => '',
            'client_reference' => 'OLD-1',
            'recipient' => ['first_name' => 'Lola', 'address_1' => '9 Old Rd', 'barangay' => 'Somewhere Old', 'city' => 'Atlantis', 'state' => 'Lost Province', 'postcode' => '9999'],
        ]))->assertSessionHasNoErrors()->assertSessionHas('status', 'Saved');

        $saved = $s->refresh()->recipient;
        $this->assertSame('Atlantis', $saved['city']);
        $this->assertSame('Lost Province', $saved['state']);
    }

    public function test_courier_tiles_disable_unset_couriers(): void
    {
        CourierAccount::factory()->create(['courier' => 'lbc', 'label' => 'LBC', 'status' => 'disabled', 'is_default' => true, 'default_slot' => 'lbc']);

        $html = $this->get('/shipments/new')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<div class="sh-couriers" role="radiogroup"/', $html);
        // In order QuadX, SPX, LBC.
        $this->assertMatchesRegularExpression('/data-courier="quadx".*data-courier="spx".*data-courier="lbc"/s', $html);
        $this->assertMatchesRegularExpression('/type="radio" name="account_id" value="'.$this->account->public_id.'"[^>]*checked/', $html);
        $this->assertMatchesRegularExpression('/data-courier="spx".*?type="radio" name="account_id"[^>]*disabled.*?Not set up/s', $html);
        // An account that exists but is not active says so.
        $this->assertMatchesRegularExpression('/data-courier="lbc".*?type="radio" name="account_id"[^>]*disabled.*?Inactive/s', $html);
        $this->assertSame(1, substr_count($html, 'Not set up'));
    }

    public function test_sender_collapsed_when_settings_sender_exists(): void
    {
        // No sender yet: expanded.
        $this->assertStringContainsString('x-data="{ open: true }"', $this->get('/shipments/new')->getContent());

        $settings = app(Settings::class);
        foreach (['contact_name' => 'Venta Warehouse', 'contact_phone' => '09181234567', 'address_1' => '5 Shaw Blvd', 'city' => 'Mandaluyong', 'state' => 'Metro Manila'] as $k => $v) {
            $settings->set("pickup.{$k}", $v);
        }

        $html = $this->get('/shipments/new')->assertOk()->getContent();
        $this->assertStringContainsString('x-data="{ open: false }"', $html);
        $this->assertMatchesRegularExpression('/class="sh-sender__card".*Venta Warehouse.*5 Shaw Blvd.*Change/s', $html);
        $this->assertStringContainsString('name="pickup[contact_name]"', $html);
    }

    public function test_form_has_no_money_inputs_and_shows_summary(): void
    {
        $html = $this->get('/shipments/new')->assertOk()->getContent();

        $this->assertStringNotContainsString('name="payment[cod_amount]"', $html);
        $this->assertStringNotContainsString('name="payment[declared_value]"', $html);
        $this->assertStringContainsString('Collect on delivery', $html);
        $this->assertStringContainsString('Declared value', $html);
        $this->assertStringContainsString('name="recipient[codes][city]"', $html);
        $this->assertStringContainsString('name="pickup[codes][city]"', $html);
        $this->assertMatchesRegularExpression('/<aside class="sh-summary".*Book shipment/s', $html);
        $this->assertMatchesRegularExpression('/<main[^>]*class="x-content x-content--wide"/', $html);
    }

    public function test_odd_but_valid_prices_count_toward_the_total(): void
    {
        $this->post('/shipments', $this->form(['payment' => ['method' => 'cod'], 'items' => [['quantity' => '+2', 'price' => '.5'], ['quantity' => ' 3 ', 'price' => '5.']]]))
            ->assertSessionHasNoErrors();

        // 2 × 0.50 + 3 × 5.00
        $s = Shipment::query()->sole();
        $this->assertSame('16.00', $s->cod_amount);
        $this->assertSame([2, 3], array_column($s->items, 'quantity'));
        $this->assertSame(['0.50', '5.00'], array_column($s->items, 'price'));
    }

    public function test_prices_and_quantities_the_total_cannot_read_are_refused(): void
    {
        foreach (['abc', '1.234', '-5', '.', '++5'] as $price) {
            $this->post('/shipments', $this->form(['payment' => ['method' => 'cod'], 'items' => [['price' => $price]]]))
                ->assertSessionHasErrors('items.0.price');
        }
        // Numeric, but not a shape the total reads.
        $this->post('/shipments', $this->form(['items' => [['price' => '1e3']]]))
            ->assertSessionHasErrors(['items.0.price' => 'Use a price like 120.50.']);
        $this->post('/shipments', $this->form(['items' => [['quantity' => '2.0']]]))
            ->assertSessionHasErrors('items.0.quantity');
        $this->post('/shipments', $this->form(['items' => [['quantity' => '1e1']]]))
            ->assertSessionHasErrors('items.0.quantity');
        $this->assertSame(0, Shipment::query()->count());
    }

    public function test_cod_without_prices_marks_the_first_unpriced_item(): void
    {
        $token = $this->token();
        $this->from('/shipments/new')->post('/shipments', $this->form(['form_token' => $token, 'payment' => ['method' => 'cod'], 'items' => [['price' => '10'], ['price' => '0']]]))
            ->assertSessionHasNoErrors();

        $this->from('/shipments/new')->post('/shipments', $this->form(['payment' => ['method' => 'cod'], 'items' => [['price' => ''], ['price' => '']]]))
            ->assertSessionHasErrors(['payment.cod_amount' => 'Add item prices.']);
        $html = $this->get('/shipments/new')->getContent();
        $this->assertStringContainsString('\\u0022price\\u0022:\\u0022Add item prices.\\u0022', $html);

        // An older COD shipment saved without item prices says so on open.
        $legacy = Shipment::factory()->create([
            'source' => 'manual', 'state' => 'failed', 'client_app_id' => null, 'courier_account_id' => $this->account->id,
            'payment_method' => 'cod', 'cod_amount' => '500.00', 'items' => [['name' => 'Tee', 'quantity' => 1, 'price' => null]],
        ]);
        $html = $this->get(route('shipments.edit', $legacy->public_id))->assertOk()->getContent();
        $this->assertStringContainsString('\\u0022price\\u0022:\\u0022Add item prices.\\u0022', $html);
        $this->assertMatchesRegularExpression('/<div class="fm-error">Add item prices\.<\/div>/', $html);
    }
}
