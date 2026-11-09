<?php

namespace Tests\Feature\Addresses;

use App\Addresses\Customers;
use App\Couriers\CourierRegistry;
use App\Couriers\FakeCourier;
use App\Models\ClientApp;
use App\Models\Customer;
use App\Models\Shipment;
use App\Shipments\ShipmentCreator;
use Illuminate\Database\DeadlockException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsShipments;
use Tests\TestCase;

class CustomersTest extends TestCase
{
    use BuildsShipments, RefreshDatabase;

    private ClientApp $client;

    private FakeCourier $fake;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->fakeDefaultAccount();
        $this->client = ClientApp::factory()->create();
        $this->fake = FakeCourier::make('quadx');
        CourierRegistry::fake('quadx', $this->fake);
    }

    private function creator(): ShipmentCreator
    {
        return app(ShipmentCreator::class);
    }

    /** A shipment booked through the API, then saved as a customer the way the form's "Save customer" does. */
    private function book(string $ref, array $recipient = []): Shipment
    {
        $shipment = $this->creator()->create($this->client, $this->shipmentBody(['client_reference' => $ref, 'recipient' => $recipient]));
        app(Customers::class)->upsertFromShipment($shipment);

        return $shipment;
    }

    public function test_an_api_shipment_never_saves_a_customer(): void
    {
        $this->creator()->create($this->client, $this->shipmentBody(['client_reference' => 'API1']), now: true);
        $draft = $this->creator()->create($this->client, $this->shipmentBody(['client_reference' => 'API2', 'submit' => false]), 'manual');
        $this->creator()->update($draft, $this->shipmentBody(['client_reference' => 'API2']));

        $this->assertSame(2, Shipment::query()->count());
        $this->assertSame(0, Customer::query()->count());
    }

    public function test_a_picked_customer_is_counted_without_touching_its_address(): void
    {
        $this->book('P1');
        $c = Customer::query()->sole();
        $this->travel(5)->minutes();

        app(Customers::class)->recordShipment($c);
        app(Customers::class)->recordShipment($c, false);

        $fresh = $c->fresh();
        $this->assertSame(2, $fresh->shipments_count);
        $this->assertSame(now()->toDateTimeString(), $fresh->last_shipped_at->toDateTimeString());
        $this->assertSame($c->address, $fresh->address);
    }

    public function test_a_created_shipment_saves_its_recipient_as_a_customer(): void
    {
        $this->book('C1', ['phone' => '0917 123 4567', 'barangay' => 'Poblacion', 'codes' => ['state' => '1300000000']]);

        $c = Customer::query()->sole();
        $this->assertStringStartsWith('cus_', $c->public_id);
        $this->assertSame(30, strlen($c->public_id));
        $this->assertSame('public_id', $c->getRouteKeyName());
        $this->assertSame('09171234567', $c->phone);
        $this->assertSame('Juan Dela Cruz', $c->name);
        $this->assertSame(1, $c->shipments_count);
        $this->assertNotNull($c->last_shipped_at);
        $this->assertSame([
            'first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'phone' => '09171234567', 'email' => null,
            'address_1' => '1 Rizal St', 'address_2' => null, 'barangay' => 'Poblacion', 'district' => null,
            'city' => 'Makati', 'state' => 'Metro Manila', 'postcode' => '1210', 'country' => 'PH',
            'codes' => ['state' => '1300000000'],
        ], $c->address);

        // The address is encrypted at rest, like a shipment's recipient.
        $this->assertStringNotContainsString('Rizal', (string) DB::table('customers')->value('address'));
    }

    public function test_phones_written_differently_are_one_customer_and_the_latest_address_wins(): void
    {
        $this->book('C1', ['phone' => '0917 123 4567']);
        $this->travel(1)->minutes();
        $this->book('C2', ['phone' => '+63 917 123 4567', 'address_1' => '2 Mabini St', 'first_name' => 'Juana']);
        $this->travel(1)->minutes();
        $this->book('C3', ['phone' => '9171234567', 'address_1' => '3 Bonifacio St']);

        $c = Customer::query()->sole();
        $this->assertSame(3, $c->shipments_count);
        $this->assertSame('3 Bonifacio St', $c->address['address_1']);
        $this->assertSame('Juan Dela Cruz', $c->name);
        $this->assertSame(now()->toDateTimeString(), $c->last_shipped_at->toDateTimeString());

        $this->book('C4', ['phone' => '09181112222']);
        $this->assertSame(2, Customer::query()->count());
    }

    public function test_saving_an_edited_draft_again_updates_the_customer_without_counting_again(): void
    {
        $draft = $this->creator()->create($this->client, $this->shipmentBody(['client_reference' => 'D1', 'submit' => false]), 'manual');
        app(Customers::class)->upsertFromShipment($draft);
        $this->creator()->update($draft, $this->shipmentBody(['client_reference' => 'D1', 'recipient' => ['address_1' => '9 New St']]));
        app(Customers::class)->upsertFromShipment($draft->refresh(), false);

        $c = Customer::query()->sole();
        $this->assertSame(1, $c->shipments_count);
        $this->assertSame('9 New St', $c->address['address_1']);
    }

    public function test_a_recipient_without_a_usable_phone_is_skipped(): void
    {
        $s = Shipment::factory()->create(['recipient' => ['first_name' => 'X', 'last_name' => 'Y', 'phone' => '12345']]);

        $this->assertNull(app(Customers::class)->upsertFromShipment($s));
        $this->assertSame(0, Customer::query()->count());
    }

    public function test_search_matches_name_or_phone_caps_results_and_needs_two_characters(): void
    {
        $customers = app(Customers::class);
        for ($i = 0; $i < 10; $i++) {
            Customer::query()->create(['name' => "Maria Santos {$i}", 'phone' => '0917000000'.$i, 'address' => ['first_name' => 'Maria'],
                'shipments_count' => 1, 'last_shipped_at' => now()->subMinutes(10 - $i)]);
        }
        Customer::query()->create(['name' => 'Pedro Penduko', 'phone' => '09181234567', 'address' => [], 'shipments_count' => 1, 'last_shipped_at' => now()]);

        $this->assertCount(0, $customers->search('m'));
        $this->assertCount(0, $customers->search('  '));
        $hits = $customers->search('maria');
        $this->assertCount(8, $hits);
        $this->assertSame('Maria Santos 9', $hits->first()->name, 'most recent first');
        $this->assertCount(3, $customers->search('santos', 3));
        $this->assertSame(['Pedro Penduko'], $customers->search('0918 123')->pluck('name')->all());
        $this->assertSame(['Pedro Penduko'], $customers->search('+63918123')->pluck('name')->all());
        $this->assertSame(['Pedro Penduko'], $customers->search('pendu')->pluck('name')->all());
        $this->assertCount(0, $customers->search('%_'));
    }

    public function test_update_and_delete(): void
    {
        $this->book('U1');
        $c = Customer::query()->sole();
        $customers = app(Customers::class);

        $customers->update($c, ['first_name' => 'Juan', 'last_name' => 'Cruz', 'phone' => '+63 917 999 0000', 'email' => '',
            'address_1' => '7 Luna St', 'address_2' => '', 'barangay' => '', 'district' => '', 'city' => 'Pasig', 'state' => 'Metro Manila',
            'postcode' => '1600', 'codes' => []]);

        $c = $c->fresh();
        $this->assertSame('Juan Cruz', $c->name);
        $this->assertSame('09179990000', $c->phone);
        $this->assertSame('7 Luna St', $c->address['address_1']);
        $this->assertSame('09179990000', $c->address['phone']);
        $this->assertNull($c->address['email']);
        $this->assertSame(1, $c->shipments_count);

        $customers->delete($c);
        $this->assertSame(0, Customer::query()->count());
        $this->assertSame(1, Shipment::query()->count());
    }

    public function test_update_reports_a_bad_phone_as_a_field_error(): void
    {
        $this->book('V1');
        $c = Customer::query()->sole();

        try {
            app(Customers::class)->update($c, ['first_name' => 'A', 'phone' => '12345']);
            $this->fail('expected a validation error');
        } catch (ValidationException $e) {
            $this->assertSame(['phone' => ['Enter a valid phone.']], $e->errors());
        }
    }

    public function test_update_reports_a_phone_used_by_another_customer(): void
    {
        $this->book('W1', ['phone' => '09171110000']);
        $this->book('W2', ['phone' => '09172220000']);
        $c = Customer::query()->where('phone', '09172220000')->sole();

        try {
            app(Customers::class)->update($c, ['first_name' => 'A', 'phone' => '+63 917 111 0000']);
            $this->fail('expected a validation error');
        } catch (ValidationException $e) {
            $this->assertSame(['phone' => ['Another customer has this phone.']], $e->errors());
        }
        $this->assertSame('09172220000', $c->fresh()->phone);
    }

    public function test_the_upsert_retries_once_after_a_deadlock_or_other_concurrency_error(): void
    {
        $s = Shipment::factory()->create(['recipient' => ['first_name' => 'R', 'last_name' => 'T', 'phone' => '09173334444']]);
        $customers = new class extends Customers
        {
            public int $calls = 0;

            protected function upsert(array $address, bool $count): Customer
            {
                if (++$this->calls === 1) {
                    throw new DeadlockException('Deadlock found when trying to get lock');
                }

                return parent::upsert($address, $count);
            }
        };

        $customer = $customers->upsertFromShipment($s);

        $this->assertSame(2, $customers->calls);
        $this->assertSame('09173334444', $customer->phone);
        $this->assertSame(1, Customer::query()->count());
    }
}
