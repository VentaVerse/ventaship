<?php

namespace Tests\Feature\Ui;

use App\Addresses\PickupAddresses;
use App\Models\Admin;
use App\Models\Customer;
use App\Models\PickupAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AddressesPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private ?Admin $admin = null;

    private function admin(): Admin
    {
        return $this->admin ??= Admin::query()->create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'correct-horse-battery']);
    }

    /** @return array<string, mixed> */
    private function pickupInput(array $over = []): array
    {
        return $over + [
            'label' => 'Warehouse', 'contact_name' => 'Ana Reyes', 'contact_phone' => '0917 123 4567',
            'address_1' => '1 Main St', 'address_2' => '',
            'address' => ['state' => 'Metro Manila', 'city' => 'Makati', 'barangay' => 'Poblacion', 'district' => '', 'postcode' => '1200', 'codes' => []],
        ];
    }

    /** @return array<string, mixed> the service takes the address block folded into the top level */
    private function flat(array $input): array
    {
        $block = $input['address'];
        unset($input['address']);

        return $input + $block;
    }

    private function customer(string $phone = '09171234567', string $first = 'Juan'): Customer
    {
        return Customer::query()->create([
            'phone' => $phone, 'name' => $first.' Cruz', 'shipments_count' => 3, 'last_shipped_at' => now(),
            'address' => ['first_name' => $first, 'last_name' => 'Cruz', 'phone' => $phone, 'address_1' => '9 Luna St', 'barangay' => 'San Antonio', 'city' => 'Pasig', 'state' => 'Metro Manila', 'postcode' => '1600', 'codes' => []],
        ]);
    }

    /** @return array<string, mixed> */
    private function customerInput(array $over = []): array
    {
        return $over + [
            'first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'phone' => '0917 123 4567', 'email' => '',
            'address_1' => '7 Luna St', 'address_2' => '',
            'address' => ['state' => 'Metro Manila', 'city' => 'Pasig', 'barangay' => 'San Antonio', 'district' => '', 'postcode' => '1600', 'codes' => []],
        ];
    }

    public function test_phone_fields_are_mobile_only_and_stored_as_09(): void
    {
        $a = $this->actingAs($this->admin());
        foreach (['91712345678', '0281234567', '12345'] as $bad) {
            $a->post(route('addresses.customers.store'), $this->customerInput(['phone' => $bad]))->assertSessionHasErrors('phone');
            $a->post(route('addresses.pickup.store'), $this->pickupInput(['contact_phone' => $bad]))->assertSessionHasErrors('contact_phone');
        }
        $this->assertSame(0, Customer::query()->count());
        $this->assertSame(0, PickupAddress::query()->count());

        $a->post(route('addresses.customers.store'), $this->customerInput(['phone' => '9171234567']))->assertSessionDoesntHaveErrors();
        $a->post(route('addresses.pickup.store'), $this->pickupInput(['contact_phone' => '+63 917-123-4567']))->assertSessionDoesntHaveErrors();
        $this->assertSame('09171234567', Customer::query()->sole()->phone);
        $this->assertSame('09171234567', PickupAddress::query()->sole()->address['contact_phone']);
    }

    public function test_edit_forms_prefill_the_ten_national_digits(): void
    {
        $c = $this->customer('09171234567');
        $this->actingAs($this->admin())->get(route('addresses.customers.edit', $c))
            ->assertSee('value="917 123 4567"', false)->assertSee('+63');
    }

    public function test_guests_are_redirected(): void
    {
        $this->get(route('addresses.index'))->assertRedirect(route('login'));
        $this->get(route('addresses.pickup.create'))->assertRedirect(route('login'));
        $this->post(route('addresses.pickup.store'), $this->pickupInput())->assertRedirect(route('login'));
        $this->assertSame(0, PickupAddress::query()->count());
    }

    public function test_the_sidebar_has_addresses_under_setup(): void
    {
        $this->actingAs($this->admin())->get(route('addresses.index'))
            ->assertOk()->assertSee('href="'.route('addresses.index').'"', false)->assertSee('Addresses');
    }

    public function test_the_pickup_tab_lists_addresses_with_the_default_badge(): void
    {
        $pickups = app(PickupAddresses::class);
        $pickups->create($this->flat($this->pickupInput()));
        $pickups->create($this->flat($this->pickupInput(['label' => 'Shop', 'contact_name' => 'Ben Lim'])));

        $res = $this->actingAs($this->admin())->get(route('addresses.index'))->assertOk();
        $res->assertSeeInOrder(['Warehouse', 'Default', 'Shop'])->assertSee('Ana Reyes')->assertSee('0917 123 4567')
            ->assertDontSee('09171234567')->assertSee('Makati')->assertSee('Add address')->assertSee('Make default');
        $this->assertSame(1, substr_count($res->getContent(), 'Make default</button>'));
    }

    public function test_the_pickup_tab_header_says_label_and_rows_open_the_address(): void
    {
        $a = app(PickupAddresses::class)->create($this->flat($this->pickupInput()));

        $html = $this->actingAs($this->admin())->get(route('addresses.index'))->assertOk()
            ->assertSee('<th scope="col">Label</th>', false)->assertDontSee('<th scope="col">Name</th>', false)->getContent();
        $this->assertStringContainsString('<a class="addr-link" href="'.route('addresses.pickup.edit', $a).'">Warehouse</a>', $html);
        $this->assertStringContainsString('data-label="Contact"', $html);
        $this->assertStringContainsString('data-label="Address"', $html);
    }

    public function test_the_pickup_tab_empty_state(): void
    {
        $this->actingAs($this->admin())->get(route('addresses.index', ['tab' => 'pickup']))->assertOk()->assertSee('No pickup addresses');
    }

    public function test_the_customers_tab_lists_customers_and_searches(): void
    {
        $this->customer('09171234567', 'Juan');
        $this->customer('09189990000', 'Maria');

        $this->actingAs($this->admin())->get(route('addresses.index', ['tab' => 'customers']))
            ->assertOk()->assertSee('Juan Cruz')->assertSee('Maria Cruz')->assertSee('0917 123 4567')->assertDontSee('09171234567')
            ->assertSee('9 Luna St');

        $this->actingAs($this->admin())->get(route('addresses.index', ['tab' => 'customers', 'q' => 'mari']))
            ->assertOk()->assertSee('Maria Cruz')->assertDontSee('Juan Cruz');
        $this->actingAs($this->admin())->get(route('addresses.index', ['tab' => 'customers', 'q' => '+63 917']))
            ->assertOk()->assertSee('Juan Cruz')->assertDontSee('Maria Cruz');
    }

    public function test_the_customers_tab_paginates_at_50(): void
    {
        for ($i = 0; $i < 51; $i++) {
            $this->customer('0917'.str_pad((string) $i, 7, '0', STR_PAD_LEFT), 'N'.$i);
        }

        $page = $this->actingAs($this->admin())->get(route('addresses.index', ['tab' => 'customers']))->assertOk();
        $this->assertSame(50, substr_count($page->getContent(), 'data-customer-row'));
        $this->actingAs($this->admin())->get(route('addresses.index', ['tab' => 'customers', 'page' => 2]))
            ->assertOk()->assertSee('51 to 51 of 51')->assertSee('data-customer-row', false);
    }

    public function test_customers_tab_empty_state_offers_add_customer(): void
    {
        $html = $this->actingAs($this->admin())->get(route('addresses.index', ['tab' => 'customers']))->assertOk()
            ->assertSee('No customers')->assertDontSee('Clear search')->getContent();
        $this->assertSame(2, substr_count($html, 'href="'.route('addresses.customers.create').'"'));
    }

    public function test_a_search_without_matches_offers_clear_search(): void
    {
        $this->customer();

        $html = $this->actingAs($this->admin())->get(route('addresses.index', ['tab' => 'customers', 'q' => '<b>zz</b>']))->assertOk()
            ->assertSee('No customers match "<b>zz</b>"')->assertSee('Clear search')->getContent();
        $this->assertStringNotContainsString('<b>zz</b>', $html);
        $this->assertStringContainsString('href="'.route('addresses.index', ['tab' => 'customers']).'"', $html);
        $this->assertSame(1, substr_count($html, 'href="'.route('addresses.customers.create').'"'));
    }

    public function test_a_one_character_search_is_not_ignored(): void
    {
        $this->customer('09171234567', 'Juan');
        $this->customer('09189990000', 'Maria');

        $this->actingAs($this->admin())->get(route('addresses.index', ['tab' => 'customers', 'q' => 'j']))
            ->assertOk()->assertSee('Juan Cruz')->assertDontSee('Maria Cruz');
        $this->actingAs($this->admin())->get(route('addresses.index', ['tab' => 'customers', 'q' => '8']))
            ->assertOk()->assertSee('Maria Cruz')->assertDontSee('Juan Cruz');
        $this->actingAs($this->admin())->get(route('addresses.index', ['tab' => 'customers', 'q' => 'x']))
            ->assertOk()->assertSee('No customers match "x"')->assertSee('Clear search');
    }

    public function test_a_nameless_customer_shows_the_grouped_phone(): void
    {
        $c = Customer::query()->create([
            'phone' => '09182223333', 'name' => '09182223333', 'shipments_count' => 1, 'last_shipped_at' => now(),
            'address' => ['phone' => '09182223333', 'address_1' => '4 Rizal Ave', 'city' => 'Bangued', 'state' => 'Abra', 'codes' => []],
        ]);

        $html = $this->actingAs($this->admin())->get(route('addresses.index', ['tab' => 'customers']))->assertOk()->getContent();
        $this->assertStringContainsString('<a class="addr-link" href="'.route('addresses.customers.edit', $c).'">0918 222 3333</a>', $html);
        $this->assertStringContainsString('aria-label="Edit 0918 222 3333"', $html);
        $this->assertStringNotContainsString('aria-label="Edit "', $html);
        foreach (['Phone', 'Address', 'Shipments', 'Last shipped'] as $label) {
            $this->assertStringContainsString('data-label="'.$label.'"', $html);
        }
    }

    public function test_an_unknown_tab_shows_pickup(): void
    {
        $this->actingAs($this->admin())->get(route('addresses.index', ['tab' => 'nope']))->assertOk()->assertSee('Add address');
    }

    public function test_create_a_pickup_address(): void
    {
        $this->actingAs($this->admin())->get(route('addresses.pickup.create'))->assertOk()
            ->assertSee('Label')->assertSee('Contact name')->assertSee('Street')->assertSee('Postcode')->assertDontSee('Delete');

        $this->actingAs($this->admin())->post(route('addresses.pickup.store'), $this->pickupInput())
            ->assertRedirect(route('addresses.index', ['tab' => 'pickup']))->assertSessionHas('status');

        $a = PickupAddress::query()->sole();
        $this->assertSame('Warehouse', $a->label);
        $this->assertTrue($a->is_default);
        $this->assertSame('09171234567', $a->address['contact_phone']);
        $this->assertSame('Makati', $a->address['city']);
        $this->assertSame('1200', $a->address['postcode']);
    }

    public function test_pickup_validation(): void
    {
        $this->actingAs($this->admin())->post(route('addresses.pickup.store'), $this->pickupInput(['label' => '', 'contact_phone' => '123', 'address_1' => '']))
            ->assertSessionHasErrors(['label', 'contact_phone', 'address_1']);
        $this->assertSame('Enter the 10-digit mobile number.', session('errors')->first('contact_phone'));
        $this->assertSame(0, PickupAddress::query()->count());
    }

    public function test_edit_and_update_a_pickup_address(): void
    {
        $a = app(PickupAddresses::class)->create($this->flat($this->pickupInput()));

        $this->actingAs($this->admin())->get(route('addresses.pickup.edit', $a))->assertOk()
            ->assertSee('Warehouse')->assertSee('1 Main St')->assertSee('Delete');

        $this->actingAs($this->admin())->put(route('addresses.pickup.update', $a), $this->pickupInput(['label' => 'HQ', 'address_1' => '2 Side St']))
            ->assertRedirect(route('addresses.index', ['tab' => 'pickup']));

        $a->refresh();
        $this->assertSame('HQ', $a->label);
        $this->assertSame('2 Side St', $a->address['address_1']);
    }

    public function test_make_default(): void
    {
        $pickups = app(PickupAddresses::class);
        $first = $pickups->create($this->flat($this->pickupInput()));
        $second = $pickups->create($this->flat($this->pickupInput(['label' => 'Shop'])));

        $this->actingAs($this->admin())->post(route('addresses.pickup.default', $second))->assertRedirect(route('addresses.index', ['tab' => 'pickup']));

        $this->assertTrue($second->fresh()->is_default);
        $this->assertFalse($first->fresh()->is_default);
    }

    public function test_deleting_the_default_promotes_the_next(): void
    {
        $pickups = app(PickupAddresses::class);
        $first = $pickups->create($this->flat($this->pickupInput()));
        $second = $pickups->create($this->flat($this->pickupInput(['label' => 'Shop'])));

        $this->actingAs($this->admin())->delete(route('addresses.pickup.destroy', $first))->assertRedirect(route('addresses.index', ['tab' => 'pickup']));

        $this->assertNull(PickupAddress::query()->find($first->id));
        $this->assertTrue($second->fresh()->is_default);
    }

    public function test_the_customers_tab_has_add_customer_and_the_pickup_tab_does_not(): void
    {
        $this->actingAs($this->admin())->get(route('addresses.index', ['tab' => 'customers']))->assertOk()
            ->assertSee('Add customer')->assertSee(route('addresses.customers.create'))->assertDontSee('Add address');
        $this->actingAs($this->admin())->get(route('addresses.index', ['tab' => 'pickup']))->assertOk()
            ->assertDontSee('Add customer');
    }

    public function test_create_a_customer(): void
    {
        $this->actingAs($this->admin())->get(route('addresses.customers.create'))->assertOk()
            ->assertSee('Add customer')->assertSee('First name')->assertSee('Last name')->assertSee('Phone')->assertSee('Street')
            ->assertSee('Postcode')->assertSee(route('addresses.customers.store'))->assertDontSee('Delete');

        $this->actingAs($this->admin())->post(route('addresses.customers.store'), $this->customerInput(['phone' => '+63 917 222 3333']))
            ->assertRedirect(route('addresses.index', ['tab' => 'customers']))->assertSessionHas('status', 'Added');

        $c = Customer::query()->sole();
        $this->assertSame('Juan Dela Cruz', $c->name);
        $this->assertSame('09172223333', $c->phone);
        $this->assertSame('7 Luna St', $c->address['address_1']);
        $this->assertSame('Pasig', $c->address['city']);
        $this->assertSame('1600', $c->address['postcode']);
        $this->assertSame(0, $c->shipments_count);
        $this->assertNull($c->last_shipped_at);

        $this->actingAs($this->admin())->get(route('addresses.index', ['tab' => 'customers']))
            ->assertSee('Juan Dela Cruz')->assertSee('Never');
    }

    public function test_create_customer_validation(): void
    {
        $this->customer('09175550000', 'Other');

        $this->actingAs($this->admin())->post(route('addresses.customers.store'), $this->customerInput(['phone' => '12']))
            ->assertSessionHasErrors('phone');
        $this->assertSame('Enter the 10-digit mobile number.', session('errors')->first('phone'));

        $this->actingAs($this->admin())->post(route('addresses.customers.store'), $this->customerInput(['phone' => '0917 555 0000']))
            ->assertSessionHasErrors('phone');
        $this->assertSame('Another customer has this phone.', session('errors')->first('phone'));

        $this->actingAs($this->admin())->post(route('addresses.customers.store'), $this->customerInput(['address_1' => '', 'address' => []]))
            ->assertSessionHasErrors(['address_1', 'address', 'address.state', 'address.city']);

        $this->assertSame(1, Customer::query()->count());
    }

    public function test_edit_and_update_a_customer(): void
    {
        $c = $this->customer();

        $this->actingAs($this->admin())->get(route('addresses.customers.edit', $c))->assertOk()
            ->assertSee('Juan')->assertSee('9 Luna St')->assertSee('Delete');

        $this->actingAs($this->admin())->put(route('addresses.customers.update', $c), $this->customerInput())
            ->assertRedirect(route('addresses.index', ['tab' => 'customers']))->assertSessionHas('status');

        $c->refresh();
        $this->assertSame('Juan Dela Cruz', $c->name);
        $this->assertSame('7 Luna St', $c->address['address_1']);
        $this->assertSame(3, $c->shipments_count);
    }

    public function test_the_customer_form_has_no_email_and_keeps_the_stored_one(): void
    {
        $c = $this->customer();
        $c->forceFill(['address' => ['email' => 'juan@example.com'] + (array) $c->address])->save();

        foreach ([route('addresses.customers.create'), route('addresses.customers.edit', $c)] as $url) {
            $html = $this->actingAs($this->admin())->get($url)->assertOk()->getContent();
            $this->assertStringNotContainsString('name="email"', $html);
            $this->assertStringNotContainsString('>Email<', $html);
        }

        $this->put(route('addresses.customers.update', $c), $this->customerInput(['email' => 'x@example.com']))
            ->assertSessionHasNoErrors();
        $c->refresh();
        $this->assertSame('7 Luna St', $c->address['address_1']);
        $this->assertSame('juan@example.com', $c->address['email']);

        $this->post(route('addresses.customers.store'), $this->customerInput(['phone' => '0917 222 3333', 'email' => 'x@example.com']))
            ->assertSessionHasNoErrors();
        $this->assertNull(Customer::query()->where('phone', '09172223333')->sole()->address['email']);
    }

    public function test_the_customer_address_line_2_round_trips(): void
    {
        $this->actingAs($this->admin())->get(route('addresses.customers.create'))->assertOk()
            ->assertSee('Address line 2')->assertSee('name="address_2"', false);

        $this->post(route('addresses.customers.store'), $this->customerInput(['address_2' => 'Unit 9']))->assertSessionHasNoErrors();
        $c = Customer::query()->sole();
        $this->assertSame('Unit 9', $c->address['address_2']);

        $this->get(route('addresses.customers.edit', $c))->assertOk()->assertSee('value="Unit 9"', false);
        $this->get(route('addresses.index', ['tab' => 'customers']))->assertSee('7 Luna St, Unit 9');

        $this->put(route('addresses.customers.update', $c), $this->customerInput(['address_2' => 'Unit 10']))->assertSessionHasNoErrors();
        $this->assertSame('Unit 10', $c->fresh()->address['address_2']);

        $this->put(route('addresses.customers.update', $c), $this->customerInput(['address_2' => '']))->assertSessionHasNoErrors();
        $this->assertNull($c->fresh()->address['address_2']);

        $this->put(route('addresses.customers.update', $c), $this->customerInput(['address_2' => str_repeat('x', 256)]))
            ->assertSessionHasErrors(['address_2' => 'Too long.']);
    }

    public function test_empty_forms_get_plain_messages(): void
    {
        $empty = ['address' => ['state' => '', 'city' => '', 'barangay' => '', 'district' => '', 'postcode' => '']];
        $forms = [
            [route('addresses.pickup.store'), ['label', 'contact_name', 'contact_phone', 'address_1']],
            [route('addresses.customers.store'), ['phone', 'address_1']],
        ];

        foreach ($forms as [$url, $required]) {
            $this->actingAs($this->admin())->post($url, $empty)->assertSessionHasErrors();
            $errors = session('errors')->getBag('default');
            foreach ($required as $key) {
                $this->assertSame('Required.', $errors->first($key), $url.' '.$key);
            }
            $this->assertSame('Choose a province.', $errors->first('address.state'));
            $this->assertSame('Choose a city or municipality.', $errors->first('address.city'));
            $this->assertSame('Choose a barangay.', $errors->first('address.barangay'));
            foreach ($errors->all() as $message) {
                foreach (['address.', 'address 1', 'contact phone', 'contact name', 'field is required', '_'] as $leak) {
                    $this->assertStringNotContainsString($leak, $message, $url);
                }
            }
        }

        $this->post(route('addresses.pickup.store'), $this->pickupInput(['address' => ['postcode' => str_repeat('9', 17)] + $this->pickupInput()['address']]))
            ->assertSessionHasErrors(['address.postcode' => 'Too long.']);
        $this->post(route('addresses.pickup.store'), $this->pickupInput(['contact_phone' => '12']))
            ->assertSessionHasErrors(['contact_phone' => 'Enter the 10-digit mobile number.']);
    }

    public function test_the_address_block_is_one_column_only_in_label_left_forms(): void
    {
        $css = file_get_contents(resource_path('css/pages/settings.css'));
        $this->assertMatchesRegularExpression('/\.st-row \.ph-addr \{\s*grid-template-columns: minmax\(0, 1fr\);/', $css);
        $this->assertMatchesRegularExpression('/\.st-row \.x-phone \{\s*max-width: 320px;/', $css);

        // The shipment form's own address grid (two columns from 640px) is not inside a label-left row.
        $components = file_get_contents(resource_path('css/blotter-components.css'));
        $this->assertMatchesRegularExpression('/@media \(min-width: 640px\) \{\s*\.ph-addr \{\s*grid-template-columns: repeat\(2, minmax\(0, 1fr\)\);/', $components);
        $form = file_get_contents(resource_path('views/shipments/form.blade.php'));
        $this->assertStringContainsString('<x-ph-address prefix="recipient"', $form);
        $this->assertStringNotContainsString('st-row', $form);

        foreach (['pickup', 'customer'] as $view) {
            $this->assertMatchesRegularExpression('/<div class="st-row">\s*<x-ph-address /', file_get_contents(resource_path("views/addresses/{$view}.blade.php")));
        }
    }

    public function test_customer_phone_errors_are_field_errors(): void
    {
        $c = $this->customer();
        $other = $this->customer('09175550000', 'Other');

        $this->actingAs($this->admin())->put(route('addresses.customers.update', $c), $this->customerInput(['phone' => '12']))
            ->assertSessionHasErrors('phone');
        $this->assertSame('Enter the 10-digit mobile number.', session('errors')->first('phone'));

        $this->actingAs($this->admin())->put(route('addresses.customers.update', $c), $this->customerInput(['phone' => '0917 555 0000']))
            ->assertRedirect()->assertSessionHasErrors('phone');
        $this->assertSame('Another customer has this phone.', session('errors')->first('phone'));
        $this->assertSame('09171234567', $c->fresh()->phone);
        $this->assertNotNull($other->fresh());
    }

    public function test_delete_a_customer(): void
    {
        $c = $this->customer();

        $this->actingAs($this->admin())->delete(route('addresses.customers.destroy', $c))->assertRedirect(route('addresses.index', ['tab' => 'customers']));

        $this->assertSame(0, Customer::query()->count());
    }

    public function test_the_pages_have_no_inline_style_or_native_dialogs(): void
    {
        $a = app(PickupAddresses::class)->create($this->flat($this->pickupInput()));
        $c = $this->customer();
        $admin = $this->admin();

        foreach ([
            route('addresses.index'), route('addresses.index', ['tab' => 'customers']), route('addresses.pickup.create'),
            route('addresses.pickup.edit', $a), route('addresses.customers.edit', $c), route('addresses.customers.create'),
        ] as $url) {
            $html = $this->actingAs($admin)->get($url)->assertOk()->getContent();
            $this->assertStringNotContainsString('style=', $html, $url);
            $this->assertStringNotContainsString('<style', $html, $url);
            $this->assertStringNotContainsString('confirm(', $html, $url);
        }
    }
}
