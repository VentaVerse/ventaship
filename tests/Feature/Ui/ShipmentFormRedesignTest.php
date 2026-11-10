<?php

namespace Tests\Feature\Ui;

use App\Addresses\PickupAddresses;
use App\Couriers\CourierAccounts;
use App\Couriers\CourierRegistry;
use App\Couriers\FakeCourier;
use App\Models\Admin;
use App\Models\CourierAccount;
use App\Models\PickupAddress;
use App\Models\Shipment;
use App\Support\PhAddress;
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
                'address_1' => '1 Rizal St', 'barangay' => 'Poblacion', 'city' => 'Makati', 'state' => 'Metro Manila', 'postcode' => '1210'],
            'item' => 'Blue Tee and Cap',
            'value' => '1300.17',
            'parcel' => ['type' => 'box'],
            'payment' => ['method' => 'prepaid'],
            'pickup_address_id' => $this->pickupId(),
        ], $over);
    }

    public function test_value_drives_total_cod_and_declared_and_tamper_ignored(): void
    {
        $this->fake->booksAs('QX-COD');

        $this->post('/shipments', $this->form(['total' => '1.00', 'payment' => ['method' => 'cod', 'cod_amount' => '1.00', 'declared_value' => '99999']]))
            ->assertSessionHasNoErrors();

        $s = Shipment::query()->sole();
        $this->assertSame('cod', $s->payment_method);
        $this->assertSame('1300.17', $s->total);
        $this->assertSame('1300.17', $s->cod_amount);
        $this->assertSame('0.00', $s->declared_value);

        $this->post('/shipments', $this->form(['insure' => '1', 'value' => '.5', 'payment' => ['method' => 'prepaid', 'cod_amount' => '500', 'declared_value' => '7']]))
            ->assertSessionHasNoErrors();
        $p = Shipment::query()->latest('id')->first();
        $this->assertSame('0.50', $p->total);
        $this->assertNull($p->cod_amount);
        $this->assertSame('0.50', $p->declared_value);
    }

    public function test_value_required_and_positive(): void
    {
        foreach (['', '0', '0.00', '-5', 'abc', '1.234', '1e3', '++5', '.'] as $value) {
            $this->post('/shipments', $this->form(['value' => $value, 'payment' => ['method' => 'cod']]))
                ->assertSessionHasErrors(['value' => 'Enter the parcel value.'])
                ->assertSessionDoesntHaveErrors(['payment.cod_amount']);
        }
        $this->assertSame(0, Shipment::query()->count());

        $this->post('/shipments', $this->form(['value' => ' +5. ']))->assertSessionHasNoErrors();
        $this->assertSame('5.00', Shipment::query()->sole()->total);
    }

    public function test_the_one_item_field_posts_as_a_single_item_and_posted_items_are_ignored(): void
    {
        $this->post('/shipments', $this->form(['item' => '  Assorted goods ', 'items' => [['name' => 'Tampered', 'quantity' => '9', 'price' => '999']]]))
            ->assertSessionHasNoErrors();

        $s = Shipment::query()->sole();
        $this->assertSame(['Assorted goods'], array_column($s->items, 'name'));
        $this->assertSame([1], array_column($s->items, 'quantity'));
        $this->assertSame([null], array_column($s->items, 'price'));

        $html = $this->get('/shipments/new')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('~<label class="fm-label"\s+for="f-item"\s*>\s*Item~', $html);
        $this->assertMatchesRegularExpression('~<input[^>]*name="item"[^>]*maxlength="255"~', $html);
        $this->assertStringNotContainsString('items[', $html);
        $this->assertStringNotContainsString('Add item', $html);
        $this->assertStringNotContainsString('Remove item', $html);
        $this->assertStringNotContainsString('Unit price', $html);
        $this->assertStringContainsString('name="value"', $html);
        $this->assertMatchesRegularExpression('/sec-payment.*Value \(₱\).*COD.*Prepaid/s', $html);
    }

    public function test_the_item_is_required_and_at_most_255_characters(): void
    {
        foreach (['', '   ', str_repeat('a', 256)] as $item) {
            $this->post('/shipments', $this->form(['item' => $item]))->assertSessionHasErrors('item');
        }
        $this->post('/shipments', $this->form(['item' => ['x']]))->assertSessionHasErrors('item');
        $form = $this->form();
        unset($form['item']);
        $this->post('/shipments', $form + ['items' => [['name' => 'Tee', 'quantity' => '1']]])->assertSessionHasErrors(['item' => 'Required.']);
        $this->assertSame(0, Shipment::query()->count());

        $this->post('/shipments', $this->form(['item' => str_repeat('a', 255)]))->assertSessionHasNoErrors();
        $this->assertSame(1, Shipment::query()->count());
    }

    public function test_edit_prefills_the_item_from_the_stored_items(): void
    {
        $base = ['source' => 'manual', 'state' => 'failed', 'client_app_id' => null, 'courier_account_id' => $this->account->id];

        $many = Shipment::factory()->create($base + ['items' => [['name' => 'Shirt', 'quantity' => 2], ['name' => 'Cap', 'quantity' => 1]]]);
        $html = $this->get(route('shipments.edit', $many->public_id))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('~name="item"[^>]*value="2 × Shirt, 1 × Cap"~u', $html);

        $one = Shipment::factory()->create($base + ['items' => [['name' => 'Assorted goods', 'quantity' => 1]]]);
        $this->assertMatchesRegularExpression('~name="item"[^>]*value="Assorted goods"~', $this->get(route('shipments.edit', $one->public_id))->getContent());

        $three = Shipment::factory()->create($base + ['items' => [['name' => 'Mug', 'quantity' => 3]]]);
        $this->assertMatchesRegularExpression('~name="item"[^>]*value="3 × Mug"~u', $this->get(route('shipments.edit', $three->public_id))->getContent());
    }

    public function test_the_summary_shows_the_item_text(): void
    {
        $base = ['source' => 'manual', 'state' => 'failed', 'client_app_id' => null, 'courier_account_id' => $this->account->id];
        $s = Shipment::factory()->create($base + ['items' => [['name' => 'Shirt', 'quantity' => 2], ['name' => 'Cap', 'quantity' => 1]]]);

        $html = $this->get(route('shipments.edit', $s->public_id))->getContent();
        $this->assertMatchesRegularExpression('~<aside class="sh-summary".*<dt>Item</dt>\s*<dd[^>]*x-text="itemText"[^>]*>2 × Shirt, 1 × Cap</dd>~su', $html);
        $this->assertStringNotContainsString('<dt>Items</dt>', $html);
    }

    public function test_edit_prefills_value_from_legacy_total(): void
    {
        $base = ['source' => 'manual', 'state' => 'failed', 'client_app_id' => null, 'courier_account_id' => $this->account->id];
        $priced = [['name' => 'Tee', 'quantity' => 2, 'price' => '10.50'], ['name' => 'Cap', 'quantity' => 1, 'price' => '3.25']];

        $withTotal = Shipment::factory()->create($base + ['total' => '999.00', 'cod_amount' => '5.00', 'items' => $priced]);
        $this->assertStringContainsString('name="value"', $html = $this->get(route('shipments.edit', $withTotal->public_id))->assertOk()->getContent());
        $this->assertMatchesRegularExpression('/name="value" value="999\.00"/', $html);

        $codOnly = Shipment::factory()->create($base + ['total' => null, 'payment_method' => 'cod', 'cod_amount' => '500.00', 'items' => $priced]);
        $this->assertMatchesRegularExpression('/name="value" value="500\.00"/', $this->get(route('shipments.edit', $codOnly->public_id))->getContent());

        $itemsOnly = Shipment::factory()->create($base + ['total' => null, 'payment_method' => 'prepaid', 'cod_amount' => null, 'items' => $priced]);
        $this->assertMatchesRegularExpression('/name="value" value="24\.25"/', $this->get(route('shipments.edit', $itemsOnly->public_id))->getContent());
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
        // A saved pickup address whose codes no longer chain is refused the same way.
        $stale = $this->savedPickup(['label' => 'Stale', 'codes' => ['state' => 'bogus']]);
        $this->post('/shipments', $this->form(['pickup_address_id' => $stale->public_id]))
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

    public function test_default_pickup_address_books_end_to_end_with_the_default_quadx_account(): void
    {
        // A legacy duplicate QuadX account (older, active) and a newer default: the default is the one booked on.
        $this->account->forceFill(['label' => 'Legacy', 'is_default' => false, 'default_slot' => null])->save();
        $default = CourierAccount::factory()->create(['courier' => 'quadx', 'label' => 'QuadX', 'status' => 'active', 'is_default' => true, 'default_slot' => 'quadx']);
        $this->fake->booksAs('QX-E2E-1');

        $ph = app(PhAddress::class);
        $district = $ph->districts('133900000')[0];
        $brgy = $ph->barangays($district['code'])[0];
        $codes = ['state' => '130000000', 'city' => '133900000', 'barangay' => $brgy['code']];
        app(PickupAddresses::class)->create([
            'label' => 'Main', 'contact_name' => 'Venta Warehouse', 'contact_phone' => '0918 123 4567', 'address_1' => '5 Shaw Blvd',
            'state' => 'Metro Manila', 'city' => 'Manila', 'district' => $district['name'], 'barangay' => $brgy['name'],
            'postcode' => '1006', 'codes' => $codes,
        ]);

        // What the add form renders is what the browser posts back.
        $dom = new \DOMDocument;
        @$dom->loadHTML($this->get('/shipments/new')->assertOk()->getContent());
        $xpath = new \DOMXPath($dom);
        $fields = [];
        foreach ($xpath->query('//input[(@name="pickup_address_id" and @checked) or @name="form_token"]') as $input) {
            $fields[] = rawurlencode($input->getAttribute('name')).'='.rawurlencode($input->getAttribute('value'));
        }
        parse_str(implode('&', $fields), $posted);
        $tile = $xpath->query('//*[@data-courier="quadx"]//input[@name="account_id"]')->item(0);
        $this->assertNotNull($tile);
        $this->assertSame(PickupAddress::query()->sole()->public_id, $posted['pickup_address_id']);
        $this->assertArrayNotHasKey('pickup', $posted);
        $this->assertSame($default->public_id, $tile->getAttribute('value'));

        $this->post('/shipments', $posted + [
            'account_id' => $tile->getAttribute('value'),
            'recipient' => ['first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'phone' => '0917 123 4567',
                'address_1' => '1 Rizal St', 'barangay' => 'Poblacion', 'city' => 'Makati', 'state' => 'Metro Manila', 'postcode' => '1210'],
            'item' => 'Blue Tee and Cap',
            'value' => '1300.17',
            'parcel' => ['type' => 'box'],
            'payment' => ['method' => 'cod'],
            'insure' => '1',
        ])->assertSessionHasNoErrors()->assertSessionHas('status', 'Booked · QX-E2E-1');

        $s = Shipment::query()->sole();
        $this->assertSame('booked', $s->state);
        $this->assertSame('QX-E2E-1', $s->tracking_number);
        $this->assertSame('1300.17', $s->cod_amount);
        $this->assertSame('1300.17', $s->declared_value);
        $this->assertSame($codes, $s->pickup['codes']);
        $this->assertSame($district['name'], $s->pickup['district']);
        $this->assertSame($brgy['name'], $s->pickup['barangay']);
        $this->assertSame(app(CourierAccounts::class)->forCourier('quadx')->id, $s->courier_account_id);
        $this->assertSame($default->id, $s->courier_account_id);
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
        CourierAccount::factory()->create(['courier' => 'lbc', 'label' => 'LBC', 'status' => 'inactive', 'is_default' => true, 'default_slot' => 'lbc']);

        $html = $this->get('/shipments/new')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<div class="sh-couriers" role="radiogroup"/', $html);
        // In order QuadX, SPX, LBC.
        $this->assertMatchesRegularExpression('/data-courier="quadx".*data-courier="spx".*data-courier="lbc"/s', $html);
        $this->assertMatchesRegularExpression('/type="radio" name="account_id" value="'.$this->account->public_id.'"[^>]*checked/', $html);
        // A courier with no account has no radio; its tile links to its account page to set it up.
        $this->assertMatchesRegularExpression('#<div class="sh-courier is-off" data-courier="spx">.*?Not set up · <a class="sh-courier__link" href="'.preg_quote(route('accounts.show', 'spx'), '#').'">Set up</a>#s', $html);
        $this->assertDoesNotMatchRegularExpression('/data-courier="spx">(?:(?!data-courier=).)*name="account_id"/s', $html);
        // An account that exists but is not active says so.
        $this->assertMatchesRegularExpression('/data-courier="lbc".*?type="radio" name="account_id"[^>]*disabled.*?Inactive/s', $html);
        $this->assertSame(1, substr_count($html, 'Not set up'));
    }

    public function test_form_has_no_money_inputs_and_shows_summary(): void
    {
        $html = $this->get('/shipments/new')->assertOk()->getContent();

        $this->assertStringNotContainsString('name="payment[cod_amount]"', $html);
        $this->assertStringNotContainsString('name="payment[declared_value]"', $html);
        $this->assertStringContainsString('Collect on delivery', $html);
        $this->assertStringContainsString('Declared value', $html);
        $this->assertStringContainsString('name="recipient[codes][city]"', $html);
        $this->assertMatchesRegularExpression('/<aside class="sh-summary".*Book shipment/s', $html);
        $this->assertMatchesRegularExpression('/<main[^>]*class="x-content"/', $html);
    }

    public function test_array_value_is_refused_not_a_500(): void
    {
        $this->from('/shipments/new')->post('/shipments', $this->form(['value' => ['x']]))
            ->assertSessionHasErrors(['value' => 'Enter the parcel value.']);
        $this->get('/shipments/new')->assertOk();
        $this->assertSame(0, Shipment::query()->count());
    }
}
