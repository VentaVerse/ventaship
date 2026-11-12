<?php

namespace Tests\Feature\Ui;

use App\Addresses\Customers;
use App\Addresses\PickupAddresses;
use App\Couriers\CourierRegistry;
use App\Couriers\FakeCourier;
use App\Models\Admin;
use App\Models\CourierAccount;
use App\Models\Customer;
use App\Models\PickupAddress;
use App\Models\Shipment;
use App\Support\PhAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use RuntimeException;
use Tests\Concerns\BuildsShipments;
use Tests\TestCase;

/** Saved addresses on the Add / Edit shipment form: the pickup card, the customer search, the district on the page. */
class ShipmentPickupAndCustomerTest extends TestCase
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
            'item' => 'Blue Tee',
            'value' => '500.00',
            'parcel' => ['type' => 'box'],
            'payment' => ['method' => 'prepaid'],
        ], $over);
    }

    private function second(): PickupAddress
    {
        return app(PickupAddresses::class)->create(['label' => 'QC branch', 'contact_name' => 'Venta QC', 'contact_phone' => '0918 765 4321',
            'address_1' => '12 Mother Ignacia Ave', 'city' => 'Quezon City', 'state' => 'Metro Manila', 'postcode' => '1103']);
    }

    public function test_the_chosen_pickup_address_is_snapshotted_and_posted_pickup_fields_are_ignored(): void
    {
        $main = $this->savedPickup();
        $qc = $this->second();
        $this->fake->booksAs('QX-PU-1');

        $this->post('/shipments', $this->form([
            'pickup_address_id' => $qc->public_id,
            'pickup' => ['contact_name' => 'Tampered', 'contact_phone' => '09990000000', 'address_1' => 'Elsewhere', 'city' => 'Makati', 'state' => 'Metro Manila'],
        ]))->assertSessionHasNoErrors();

        $s = Shipment::query()->sole();
        $this->assertSame($qc->toPickup()['address_1'], $s->pickup['address_1']);
        $this->assertSame('Venta QC', $s->pickup['contact_name']);
        $this->assertSame('09187654321', $s->pickup['contact_phone']);
        $this->assertSame('Quezon City', $s->pickup['city']);

        // Editing or deleting the saved address later leaves the booked snapshot alone.
        app(PickupAddresses::class)->update($qc, ['label' => 'QC', 'contact_name' => 'Renamed'] + $qc->toPickup());
        app(PickupAddresses::class)->delete($qc->refresh());
        $this->assertSame('Venta QC', $s->refresh()->pickup['contact_name']);
        $this->assertTrue($main->refresh()->is_default);
    }

    public function test_a_tampered_missing_or_internal_pickup_address_id_is_refused(): void
    {
        $main = $this->savedPickup();

        foreach (['pad_doesnotexist', (string) $main->id, 'current', ''] as $bad) {
            $this->postJson('/shipments', $this->form(['pickup_address_id' => $bad]))
                ->assertStatus(422)->assertJsonValidationErrors('pickup_address_id');
        }
        $this->post('/shipments', $this->form(['pickup_address_id' => ['x']]))->assertSessionHasErrors('pickup_address_id');
        $this->post('/shipments', $this->form())->assertSessionHasErrors(['pickup_address_id' => 'Choose a pickup address.']);
        $this->assertSame(0, Shipment::query()->count());
    }

    public function test_the_form_shows_the_default_card_and_a_radio_per_saved_address_and_no_sender_inputs(): void
    {
        $main = $this->savedPickup();
        $qc = $this->second();

        $html = $this->get('/shipments/new')->assertOk()->getContent();

        $this->assertStringNotContainsString('name="pickup[', $html);
        $this->assertStringNotContainsString('Sender', $html);
        $this->assertStringContainsString('id="sec-pickup"', $html);
        $this->assertMatchesRegularExpression('/type="radio"[^>]*name="pickup_address_id" value="'.$main->public_id.'"[^>]*checked/', $html);
        $this->assertMatchesRegularExpression('/type="radio"[^>]*name="pickup_address_id" value="'.$qc->public_id.'"/', $html);
        $this->assertDoesNotMatchRegularExpression('/value="'.$qc->public_id.'"[^>]*checked/', $html);
        $this->assertMatchesRegularExpression('/Main.*Default.*Venta Warehouse.*5 Shaw Blvd.*QC branch.*12 Mother Ignacia Ave/s', $html);
        $this->assertStringContainsString('>Change<', $html);
        $this->assertStringNotContainsString(route('addresses.pickup.create'), $html);
        $this->assertStringNotContainsString('style=', $html);
    }

    public function test_with_no_saved_pickup_address_the_form_links_to_add_one(): void
    {
        $html = $this->get('/shipments/new')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<a href="'.preg_quote(route('addresses.pickup.create'), '/').'"[^>]*>.*Add pickup address/s', $html);
        $this->assertStringNotContainsString('name="pickup_address_id"', $html);
    }

    public function test_an_edit_whose_snapshot_matches_a_saved_address_selects_it(): void
    {
        $main = $this->savedPickup();
        $qc = $this->second();
        $s = $this->editable(['pickup' => $qc->toPickup()]);

        $html = $this->get(route('shipments.edit', $s->public_id))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/name="pickup_address_id" value="'.$qc->public_id.'"[^>]*checked/', $html);
        $this->assertStringNotContainsString('value="current"', $html);
    }

    public function test_an_edit_with_its_own_snapshot_shows_current_and_keeps_it_or_takes_a_saved_one(): void
    {
        $main = $this->savedPickup();
        $snapshot = ['contact_name' => 'Old Shop', 'contact_phone' => '09181112222', 'address_1' => '7 Old St', 'address_2' => null,
            'barangay' => null, 'district' => null, 'city' => 'Makati', 'state' => 'Metro Manila', 'postcode' => '1200', 'country' => 'PH', 'codes' => []];
        $s = $this->editable(['pickup' => $snapshot]);

        $html = $this->get(route('shipments.edit', $s->public_id))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/name="pickup_address_id" value="current"[^>]*checked/', $html);
        $this->assertMatchesRegularExpression('/Current.*Old Shop.*7 Old St.*Main.*Venta Warehouse/s', $html);
        $this->assertDoesNotMatchRegularExpression('/value="'.$main->public_id.'"[^>]*checked/', $html);

        $edit = fn (array $over) => $this->put(route('shipments.update', $s->public_id), $this->form(['form_token' => '', 'client_reference' => 'REF-E'] + $over));

        $edit(['pickup_address_id' => 'current', 'pickup' => ['contact_name' => 'Tampered']])->assertSessionHasNoErrors();
        $this->assertSame('Old Shop', $s->refresh()->pickup['contact_name']);
        $this->assertSame('7 Old St', $s->pickup['address_1']);

        $edit(['pickup_address_id' => $main->public_id])->assertSessionHasNoErrors();
        $this->assertSame('Venta Warehouse', $s->refresh()->pickup['contact_name']);
    }

    public function test_customer_search_returns_only_the_needed_fields_capped_at_eight(): void
    {
        for ($i = 0; $i < 10; $i++) {
            Customer::query()->create(['phone' => sprintf('0917000%04d', $i), 'name' => "Juan {$i}", 'shipments_count' => 1, 'last_shipped_at' => now()->subMinutes($i),
                'address' => ['first_name' => 'Juan', 'last_name' => (string) $i, 'phone' => sprintf('0917000%04d', $i), 'email' => 'juan@example.com',
                    'address_1' => '1 Rizal St', 'address_2' => 'Unit 2', 'barangay' => 'Barangay 659', 'district' => 'Ermita', 'city' => 'Manila',
                    'state' => 'Metro Manila', 'postcode' => '1000', 'country' => 'PH', 'codes' => ['state' => '130000000', 'city' => '133900000']]]);
        }
        Customer::query()->create(['phone' => '09205550101', 'name' => 'Ana Reyes', 'shipments_count' => 1, 'last_shipped_at' => now(),
            'address' => ['first_name' => 'Ana', 'phone' => '09205550101', 'barangay' => 'Lahug', 'city' => 'Cebu City', 'state' => 'Cebu']]);

        $json = $this->getJson(route('customers.search', ['q' => 'juan']))->assertOk()->json();

        $this->assertCount(8, $json['customers']);
        $first = $json['customers'][0];
        $this->assertSame(['public_id', 'name', 'phone', 'summary', 'address'], array_keys($first));
        $this->assertSame('Juan 0', $first['name']);
        $this->assertSame('09170000000', $first['phone']);
        $this->assertSame('Ermita, Manila', $first['summary']);
        $this->assertSame(['first_name', 'last_name', 'phone', 'address_1', 'address_2', 'barangay', 'district', 'city', 'state', 'postcode', 'codes'], array_keys($first['address']));
        $this->assertStringNotContainsString('juan@example.com', json_encode($json));

        $ana = $this->getJson(route('customers.search', ['q' => '0920 555']))->assertOk()->json('customers');
        $this->assertSame('Lahug, Cebu City', $ana[0]['summary']);

        $this->getJson(route('customers.search', ['q' => 'j']))->assertOk()->assertExactJson(['customers' => []]);
        $this->getJson(route('customers.search', ['q' => ['x']]))->assertOk()->assertExactJson(['customers' => []]);
    }

    public function test_customer_search_requires_auth(): void
    {
        auth()->logout();

        $this->getJson(route('customers.search', ['q' => 'juan']))->assertUnauthorized();
        $this->get(route('customers.search', ['q' => 'juan']))->assertRedirect(route('login'));
    }

    public function test_the_form_has_the_customer_search_combobox(): void
    {
        $this->savedPickup();
        $html = $this->get('/shipments/new')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/role="combobox"[^>]*aria-controls="f-customer-list"/', $html);
        $this->assertStringContainsString('role="listbox"', $html);
        $this->assertStringContainsString('New customer', $html);
        $this->assertStringContainsString('customers\\\\\\/search', $html);
        $this->assertMatchesRegularExpression('/id="sec-customer".*role="combobox".*name="recipient\[first_name\]"/s', $html);
    }

    public function test_the_shipment_page_address_includes_the_district(): void
    {
        $s = Shipment::factory()->create(['recipient' => ['first_name' => 'Juan', 'phone' => '09171234567', 'address_1' => '1 Rizal St',
            'barangay' => 'Barangay 659', 'district' => 'Ermita', 'city' => 'Manila', 'state' => 'Metro Manila', 'postcode' => '1000']]);

        $this->get(route('shipments.show', $s->public_id))->assertOk()
            ->assertSeeText('1 Rizal St, Barangay 659, Ermita, Manila, Metro Manila, 1000');
    }

    public function test_a_districted_saved_address_books_end_to_end(): void
    {
        $ph = app(PhAddress::class);
        $district = $ph->districts('133900000')[0];
        $brgy = $ph->barangays($district['code'])[0];
        $codes = ['state' => '130000000', 'city' => '133900000', 'barangay' => $brgy['code']];
        $a = app(PickupAddresses::class)->create(['label' => 'Manila', 'contact_name' => 'Venta Manila', 'contact_phone' => '0918 123 4567',
            'address_1' => '1 General Luna St', 'state' => 'Metro Manila', 'city' => 'Manila', 'district' => $district['name'],
            'barangay' => $brgy['name'], 'postcode' => '1002', 'codes' => $codes]);
        $this->fake->booksAs('QX-PU-2');

        $this->post('/shipments', $this->form(['pickup_address_id' => $a->public_id]))->assertSessionHasNoErrors();

        $s = Shipment::query()->sole();
        $this->assertSame('booked', $s->state);
        $this->assertSame($codes, $s->pickup['codes']);
        $this->assertSame($district['name'], $s->pickup['district']);
    }

    public function test_a_new_customer_is_saved_only_with_save_customer_on(): void
    {
        $this->savedPickup();
        $this->fake->booksAs('QX-C1');

        $this->post('/shipments', $this->form(['pickup_address_id' => $this->pickupId()]))->assertSessionHasNoErrors();
        $this->post('/shipments', $this->form(['pickup_address_id' => $this->pickupId(), 'save_customer' => '0']))->assertSessionHasNoErrors();
        $this->assertSame(0, Customer::query()->count());

        $this->post('/shipments', $this->form(['pickup_address_id' => $this->pickupId(), 'save_customer' => '1']))->assertSessionHasNoErrors();
        $c = Customer::query()->sole();
        $this->assertSame('09171234567', $c->phone);
        $this->assertSame('Juan Dela Cruz', $c->name);
        $this->assertSame(1, $c->shipments_count);
        $this->assertSame('1 Rizal St', $c->address['address_1']);
    }

    public function test_a_picked_customer_is_counted_and_keeps_its_saved_address(): void
    {
        $this->savedPickup();
        $c = Customer::query()->create(['phone' => '09171234567', 'name' => 'Juan Saved', 'shipments_count' => 4, 'last_shipped_at' => now()->subWeek(),
            'address' => ['first_name' => 'Juan', 'last_name' => 'Saved', 'phone' => '09171234567', 'address_1' => '99 Saved St', 'city' => 'Makati', 'state' => 'Metro Manila']]);

        $this->post('/shipments', $this->form(['pickup_address_id' => $this->pickupId(), 'customer_id' => $c->public_id, 'save_customer' => '1']))
            ->assertSessionHasNoErrors();

        $fresh = $c->fresh();
        $this->assertSame(5, $fresh->shipments_count);
        $this->assertTrue($fresh->last_shipped_at->isToday());
        $this->assertSame('99 Saved St', $fresh->address['address_1']);
        $this->assertSame('Juan Saved', $fresh->name);
        $this->assertSame(1, Customer::query()->count());

        // Saving an edit of that shipment does not count it twice.
        $s = Shipment::query()->sole();
        $s->forceFill(['state' => 'failed'])->save();
        $this->put(route('shipments.update', $s->public_id), $this->form(['form_token' => '', 'client_reference' => $s->client_reference,
            'pickup_address_id' => $this->pickupId(), 'customer_id' => $c->public_id]))->assertSessionHasNoErrors();
        $this->assertSame(5, $c->fresh()->shipments_count);
    }

    public function test_an_unknown_customer_id_is_refused(): void
    {
        $this->savedPickup();

        $this->postJson('/shipments', $this->form(['pickup_address_id' => $this->pickupId(), 'customer_id' => 'cus_nope']))
            ->assertStatus(422)->assertJsonValidationErrors('customer_id');
        $this->assertSame(0, Shipment::query()->count());
    }

    public function test_saving_the_customer_failing_never_fails_the_booking(): void
    {
        $this->savedPickup();
        $customers = Mockery::mock(Customers::class);
        $customers->shouldReceive('upsertFromShipment')->andThrow(new RuntimeException('boom'));
        $this->app->instance(Customers::class, $customers);
        Log::spy();
        $this->fake->booksAs('QX-C2');

        $this->post('/shipments', $this->form(['pickup_address_id' => $this->pickupId(), 'save_customer' => '1']))
            ->assertSessionHasNoErrors()->assertSessionHas('booked', true);

        $s = Shipment::query()->sole();
        $this->assertSame('booked', $s->state);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $m, array $ctx) => $m === 'Saving the customer failed' && $ctx['shipment_id'] === $s->id)->once();
    }

    public function test_the_form_has_a_save_customer_switch(): void
    {
        $this->savedPickup();
        $html = $this->get('/shipments/new')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/type="checkbox"[^>]*role="switch"[^>]*name="save_customer" value="1"/', $html);
        $this->assertDoesNotMatchRegularExpression('/name="save_customer" value="1"[^>]*checked/', $html);
        $this->assertStringContainsString('Save customer', $html);
        $this->assertMatchesRegularExpression('/type="hidden" name="customer_id"/', $html);
    }

    private function editable(array $attributes): Shipment
    {
        return Shipment::factory()->create($attributes + [
            'source' => 'manual', 'state' => 'failed', 'client_app_id' => null, 'courier_account_id' => $this->account->id,
            'client_reference' => 'REF-E', 'active_reference' => 'REF-E',
            'recipient' => ['first_name' => 'Juan', 'phone' => '09171234567', 'address_1' => '1 Rizal St', 'city' => 'Makati',
                'state' => 'Metro Manila', 'postcode' => '1210', 'country' => 'PH'],
        ]);
    }
}
