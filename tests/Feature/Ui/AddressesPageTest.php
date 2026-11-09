<?php

namespace Tests\Feature\Ui;

use App\Addresses\Customers;
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
            'address' => ['state' => 'Metro Manila', 'city' => 'Makati', 'barangay' => '', 'district' => '', 'postcode' => '1200', 'codes' => []],
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
            'address' => ['first_name' => $first, 'last_name' => 'Cruz', 'phone' => $phone, 'address_1' => '9 Luna St', 'city' => 'Pasig', 'state' => 'Metro Manila', 'postcode' => '1600', 'codes' => []],
        ]);
    }

    /** @return array<string, mixed> */
    private function customerInput(array $over = []): array
    {
        return $over + [
            'first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'phone' => '0917 123 4567', 'email' => '',
            'address_1' => '7 Luna St', 'address_2' => '',
            'address' => ['state' => 'Metro Manila', 'city' => 'Pasig', 'barangay' => '', 'district' => '', 'postcode' => '1600', 'codes' => []],
        ];
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
        $res->assertSeeInOrder(['Warehouse', 'Default', 'Shop'])->assertSee('Ana Reyes')->assertSee('09171234567')
            ->assertSee('Makati')->assertSee('Add address')->assertSee('Make default');
        $this->assertSame(1, substr_count($res->getContent(), 'Make default</button>'));
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
            ->assertOk()->assertSee('Juan Cruz')->assertSee('Maria Cruz')->assertSee('09171234567')->assertSee('9 Luna St');

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

    public function test_customers_tab_empty_state(): void
    {
        $this->actingAs($this->admin())->get(route('addresses.index', ['tab' => 'customers']))->assertOk()->assertSee('No customers');
        $this->actingAs($this->admin())->get(route('addresses.index', ['tab' => 'customers', 'q' => 'zz']))->assertOk()->assertSee('No customers');
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
        $this->assertSame('Enter a valid phone.', session('errors')->first('contact_phone'));
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
        $this->assertSame('Enter a valid phone.', session('errors')->first('phone'));

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

    public function test_the_customer_form_has_no_email_or_address_line_2_and_keeps_stored_ones(): void
    {
        $c = $this->customer();
        $c->forceFill(['address' => ['email' => 'juan@example.com', 'address_2' => 'Unit 4'] + (array) $c->address])->save();

        foreach ([route('addresses.customers.create'), route('addresses.customers.edit', $c)] as $url) {
            $html = $this->actingAs($this->admin())->get($url)->assertOk()->getContent();
            $this->assertStringNotContainsString('name="email"', $html);
            $this->assertStringNotContainsString('name="address_2"', $html);
            $this->assertStringNotContainsString('Address line 2', $html);
            $this->assertStringNotContainsString('>Email<', $html);
        }

        $this->put(route('addresses.customers.update', $c), $this->customerInput(['email' => 'x@example.com', 'address_2' => 'Hijack']))
            ->assertSessionHasNoErrors();
        $c->refresh();
        $this->assertSame('7 Luna St', $c->address['address_1']);
        $this->assertSame('juan@example.com', $c->address['email']);
        $this->assertSame('Unit 4', $c->address['address_2']);

        $this->post(route('addresses.customers.store'), $this->customerInput(['phone' => '0917 222 3333', 'email' => 'x@example.com', 'address_2' => 'Unit 9']))
            ->assertSessionHasNoErrors();
        $new = Customer::query()->where('phone', '09172223333')->sole();
        $this->assertNull($new->address['email']);
        $this->assertNull($new->address['address_2']);
    }

    public function test_customer_phone_errors_are_field_errors(): void
    {
        $c = $this->customer();
        $other = $this->customer('09175550000', 'Other');

        $this->actingAs($this->admin())->put(route('addresses.customers.update', $c), $this->customerInput(['phone' => '12']))
            ->assertSessionHasErrors('phone');
        $this->assertSame('Enter a valid phone.', session('errors')->first('phone'));

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
