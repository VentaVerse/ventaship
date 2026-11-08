<?php

namespace Tests\Feature\Ui;

use App\Couriers\AccountSettings;
use App\Couriers\CourierRegistry;
use App\Couriers\FakeCourier;
use App\Models\Admin;
use App\Models\CourierAccount;
use App\Models\Shipment;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class ShipmentCourierFieldsTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, CourierAccount> */
    private array $accounts = [];

    /** @var array<string, FakeCourier> */
    private array $fakes = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->actingAs(Admin::query()->create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'correct-horse-battery']));
    }

    /** One account per courier. Faked couriers offer the real adapter's packaging list. */
    private function accounts(bool $fake = true): void
    {
        foreach (['quadx', 'spx', 'lbc'] as $courier) {
            if ($fake) {
                $this->fakes[$courier] = FakeCourier::make($courier)->offersParcels(CourierRegistry::describe($courier)->getParcelOptions());
                CourierRegistry::fake($courier, $this->fakes[$courier]);
            }
            $this->accounts[$courier] = CourierAccount::factory()->create([
                'courier' => $courier, 'label' => strtoupper($courier), 'is_default' => true, 'default_slot' => $courier,
            ]);
        }
    }

    private function token(): string
    {
        preg_match('/name="form_token" value="([0-9a-f-]{36})"/', $this->get('/shipments/new')->assertOk()->getContent(), $m);
        $this->assertNotEmpty($m, 'form_token missing');

        return $m[1];
    }

    /** A valid form for $courier; $over is merged recursively. */
    private function form(string $courier, array $over = []): array
    {
        $own = match ($courier) {
            'quadx' => ['parcel' => ['type' => 'box'], 'payment' => ['shipping_payment' => 'cash']],
            'spx' => ['parcel' => ['type' => 'small', 'weight_kg' => '1.2', 'category' => 'General merchandise'],
                'payment' => ['shipping_payment' => 'sender_pay'], 'handover' => 'dropoff'],
            'lbc' => ['parcel' => ['type' => '45', 'weight_kg' => '1.2'], 'pickup_date' => $this->lbcDates()[0]],
        };

        return array_replace_recursive([
            'form_token' => $this->token(),
            'account_id' => $this->accounts[$courier]->public_id,
            'recipient' => ['first_name' => 'Juan', 'phone' => '0917 123 4567', 'address_1' => '1 Rizal St',
                'city' => 'Makati', 'state' => 'Metro Manila', 'postcode' => '1210'],
            'items' => [['name' => 'Blue Tee', 'quantity' => '2']],
            'value' => '1000',
            'payment' => ['method' => 'prepaid'],
            'pickup' => ['contact_name' => 'Venta Warehouse', 'contact_phone' => '09181234567', 'address_1' => '5 Shaw Blvd',
                'city' => 'Mandaluyong', 'state' => 'Metro Manila', 'postcode' => '1550'],
        ], $own, $over);
    }

    /** @return list<string> the LBC pickup dates the form offers */
    private function lbcDates(): array
    {
        preg_match_all('/name="pickup_date"[^>]*>(.*?)<\/select>/s', $this->get('/shipments/new')->getContent(), $m);
        preg_match_all('/<option value="(\d{4}-\d{2}-\d{2})"/', $m[1][0] ?? '', $dates);

        return $dates[1];
    }

    private function latest(): Shipment
    {
        return Shipment::query()->latest('id')->firstOrFail();
    }

    public function test_packaging_options_per_courier_rendered(): void
    {
        CourierRegistry::clearFakes();
        $this->accounts(fake: false);
        AccountSettings::for($this->accounts['quadx'])->write('quadx_default_parcel', 'medium-pouch');

        $html = $this->get('/shipments/new')->assertOk()
            ->assertSeeText(['Packaging', 'White Small Pouch', 'Blue Medium Pouch', 'Red Large Pouch', 'Oversized', 'Own packaging',
                'N-Pack Small', 'N-Pack Large', 'Kilobox Mini', 'Kilobox Small', 'Kilobox Slim', 'Kilobox Medium', 'Kilobox Large',
                'Kilobox XL', 'Own box', 'More', 'N-Pouch SS', 'Sack'])
            ->assertDontSeeText('Custom dimensions')
            ->getContent();

        foreach (['small-pouch', 'medium-pouch', 'big-pouch', 'box', 'oversized', 'small', 'medium', 'large', 'custom',
            '45', '8', '165', '6', '164', '63', '7', '64', '16', '62', '3', '61', '9', '2', '4', '79', '80'] as $value) {
            $this->assertMatchesRegularExpression('/<input type="radio"[^>]*name="parcel\[type\]"[^>]*value="'.preg_quote($value, '/').'"/', $html, $value);
        }

        // The account's default parcel starts chosen; nothing else does.
        $this->assertMatchesRegularExpression('/value="medium-pouch"[^>]*checked/', $html);
        $this->assertSame(1, preg_match_all('/name="parcel\[type\]"[^>]*checked/', $html));

        // LBC: the nine first, then the rest behind More.
        $more = strpos($html, '>More<');
        $this->assertNotFalse($more);
        $this->assertLessThan($more, strpos($html, 'value="16"'));
        $this->assertLessThan($more, strpos($html, 'value="45"'));
        $this->assertGreaterThan($more, strpos($html, 'value="62"'));
        $this->assertGreaterThan($more, strpos($html, 'value="80"'));

        // Courier-specific fields.
        $this->assertStringContainsString('name="parcel[category]"', $html);
        $this->assertStringContainsString('name="payment[shipping_payment]"', $html);
        $this->assertStringContainsString('name="pickup_date"', $html);
        $this->assertStringContainsString('name="note"', $html);
        foreach (['Cash at pickup', 'Deduct from remittance', 'I pay', 'Receiver pays', 'Drop off myself', 'Pick up', 'Load times', 'Delivery note', 'Item category'] as $text) {
            $this->assertStringContainsString($text, $html, $text);
        }
        Http::assertNothingSent();
    }

    public function test_weight_required_for_spx_lbc_not_quadx(): void
    {
        $this->accounts();

        $this->post('/shipments', $this->form('quadx', ['parcel' => ['weight_kg' => '1.2']]))->assertSessionHasNoErrors();
        $this->assertArrayNotHasKey('weight_kg', $this->latest()->options['parcel']);
        $this->assertSame('box', $this->latest()->options['parcel']['type']);

        $this->post('/shipments', $this->form('spx', ['parcel' => ['weight_kg' => '']]))->assertSessionHasErrors('parcel.weight_kg');
        $this->post('/shipments', $this->form('lbc', ['parcel' => ['weight_kg' => '']]))->assertSessionHasErrors('parcel.weight_kg');

        $this->post('/shipments', $this->form('spx'))->assertSessionHasNoErrors();
        $this->assertEquals('1.2', $this->latest()->options['parcel']['weight_kg']);
    }

    public function test_size_required_only_for_own_packaging(): void
    {
        $this->accounts();
        $dims = ['length_cm' => '30', 'width_cm' => '20', 'height_cm' => '10'];

        $this->post('/shipments', $this->form('spx', ['parcel' => ['type' => 'custom']]))
            ->assertSessionHasErrors(['parcel.length_cm', 'parcel.width_cm', 'parcel.height_cm']);
        $this->post('/shipments', $this->form('lbc', ['parcel' => ['type' => '16']]))
            ->assertSessionHasErrors(['parcel.length_cm', 'parcel.width_cm', 'parcel.height_cm']);

        $this->post('/shipments', $this->form('spx', ['parcel' => ['type' => 'custom'] + $dims]))->assertSessionHasNoErrors();
        $this->assertEquals('30', $this->latest()->options['parcel']['length_cm']);

        // Presets carry their own size: a posted one is dropped.
        $this->post('/shipments', $this->form('spx', ['parcel' => $dims]))->assertSessionHasNoErrors();
        $this->assertArrayNotHasKey('length_cm', $this->latest()->options['parcel']);
        $this->post('/shipments', $this->form('lbc', ['parcel' => $dims]))->assertSessionHasNoErrors();
        $this->assertArrayNotHasKey('length_cm', $this->latest()->options['parcel']);

        // Kilobox: optional, all three or none.
        $this->post('/shipments', $this->form('lbc', ['parcel' => ['type' => '63']]))->assertSessionHasNoErrors();
        $this->post('/shipments', $this->form('lbc', ['parcel' => ['type' => '63', 'length_cm' => '30']]))
            ->assertSessionHasErrors(['parcel.width_cm', 'parcel.height_cm']);
        $this->post('/shipments', $this->form('lbc', ['parcel' => ['type' => '63'] + $dims]))->assertSessionHasNoErrors();
        $this->assertEquals('10', $this->latest()->options['parcel']['height_cm']);

        // QuadX never takes a size.
        $this->post('/shipments', $this->form('quadx', ['parcel' => $dims]))->assertSessionHasNoErrors();
        $this->assertArrayNotHasKey('length_cm', $this->latest()->options['parcel']);
    }

    public function test_spx_category_and_shipping_payment_posted(): void
    {
        $this->accounts();
        $slot = $this->slot(now()->addDay()->timestamp);

        $this->post('/shipments', $this->form('spx', [
            'parcel' => ['category' => 'Electronics'], 'payment' => ['shipping_payment' => 'receiver_pay'],
            'handover' => 'pickup', 'pickup_slot' => $slot, 'note' => 'Call first',
        ]))->assertSessionHasNoErrors();

        $s = $this->latest();
        $this->assertSame('Electronics', $s->options['parcel']['category']);
        $this->assertSame('receiver_pay', $s->options['shipping_payment']);
        $this->assertSame($slot, $s->options['pickup_slot']);
        $this->assertSame('Call first', $s->courier_note);
        $opts = $this->fakes['spx']->callsTo('createShipment')[0]['args'][1];
        $this->assertSame('Electronics', $opts['category']);
        $this->assertSame('receiver_pay', $opts['shipping_payment']);
        $this->assertSame($slot, $opts['pickup_slot']);

        // Drop off sends no slot.
        $this->post('/shipments', $this->form('spx', ['handover' => 'dropoff', 'pickup_slot' => $slot]))->assertSessionHasNoErrors();
        $this->assertArrayNotHasKey('pickup_slot', $this->latest()->options);
        $this->assertSame('General merchandise', $this->latest()->options['parcel']['category']);

        $this->post('/shipments', $this->form('spx', ['handover' => 'pickup']))->assertSessionHasErrors('pickup_slot');
        $this->post('/shipments', $this->form('spx', ['parcel' => ['category' => 'Toys']]))->assertSessionHasErrors('parcel.category');
        $this->post('/shipments', $this->form('spx', ['payment' => ['shipping_payment' => 'cash']]))->assertSessionHasErrors('payment.shipping_payment');
        $this->post('/shipments', $this->form('spx', ['note' => str_repeat('a', 257)]))->assertSessionHasErrors('note');

        $this->post('/shipments', $this->form('quadx', ['payment' => ['shipping_payment' => 'net_off']]))->assertSessionHasNoErrors();
        $this->assertSame('net_off', $this->latest()->options['shipping_payment']);
        $this->post('/shipments', $this->form('quadx', ['payment' => ['shipping_payment' => 'sender_pay']]))->assertSessionHasErrors('payment.shipping_payment');
    }

    public function test_other_courier_fields_ignored(): void
    {
        $this->accounts();
        $date = $this->lbcDates()[0];

        $this->post('/shipments', $this->form('lbc', [
            'service' => 'same_day_pickup', 'note' => 'Hi', 'pickup_slot' => 'x', 'handover' => 'pickup',
            'parcel' => ['category' => 'Electronics'], 'payment' => ['shipping_payment' => 'cash'],
        ]))->assertSessionHasNoErrors();
        $s = $this->latest();
        $this->assertNull($s->service);
        $this->assertNull($s->courier_note);
        $this->assertArrayNotHasKey('category', $s->options['parcel']);
        $this->assertArrayNotHasKey('shipping_payment', $s->options);
        $this->assertArrayNotHasKey('pickup_slot', $s->options);
        $this->assertSame($date, $s->options['pickup_date']);

        $this->post('/shipments', $this->form('quadx', [
            'service' => 'next_day', 'note' => 'Gate 2', 'pickup_date' => $date, 'pickup_slot' => 'x', 'handover' => 'pickup',
            'parcel' => ['category' => 'Toys', 'weight_kg' => 'abc'],
        ]))->assertSessionHasNoErrors();
        $q = $this->latest();
        $this->assertSame('next_day', $q->service);
        $this->assertSame('Gate 2', $q->courier_note);
        $this->assertSame('cash', $q->options['shipping_payment']);
        $this->assertSame(['type' => 'box'], $q->options['parcel']);
        $this->assertArrayNotHasKey('pickup_date', $q->options);
        $this->assertArrayNotHasKey('pickup_slot', $q->options);

        $this->post('/shipments', $this->form('spx', ['service' => 'standard', 'pickup_date' => $date]))->assertSessionHasNoErrors();
        $this->assertNull($this->latest()->service);
        $this->assertArrayNotHasKey('pickup_date', $this->latest()->options);
    }

    public function test_spx_slots_endpoint_success_and_courier_error(): void
    {
        $this->accounts();
        $query = http_build_query(['account_id' => $this->accounts['spx']->public_id, 'pickup' => [
            'contact_name' => 'Venta Warehouse', 'contact_phone' => '09181234567', 'address_1' => '5 Shaw Blvd',
            'city' => 'Mandaluyong', 'state' => 'Metro Manila', 'postcode' => '1550',
        ]]);
        $slots = [['value' => $this->slot(now()->addDay()->timestamp), 'label' => 'Tue, Nov 10'], ['value' => $this->slot(now()->addDays(2)->timestamp, 1), 'label' => 'Wed, Nov 11']];
        $this->fakes['spx']->slots($slots);

        $this->getJson('/shipments/options/spx-slots?'.$query)->assertOk()->assertExactJson(['slots' => $slots]);
        $this->assertCount(1, $this->fakes['spx']->callsTo('getPickupSlots'));

        $this->fakes['spx']->slots([]);
        $this->getJson('/shipments/options/spx-slots?'.$query)->assertStatus(502)->assertExactJson(['error' => "Couldn't load."]);

        $this->fakes['spx']->throws(new RuntimeException('SPX down'), 'getPickupSlots');
        $this->getJson('/shipments/options/spx-slots?'.$query)->assertStatus(502)->assertExactJson(['error' => "Couldn't load."]);

        // Not an SPX account.
        $this->getJson('/shipments/options/spx-slots?account_id='.$this->accounts['quadx']->public_id)->assertStatus(404)->assertExactJson(['error' => "Couldn't load."]);

        // Rendering the form never asks SPX for times.
        $before = count($this->fakes['spx']->callsTo('getPickupSlots'));
        $this->get('/shipments/new')->assertOk();
        $this->assertCount($before, $this->fakes['spx']->callsTo('getPickupSlots'));
    }

    /** An SPX pickup slot in the shape getPickupSlots() hands out. */
    private function slot(int $time, int $range = 0): string
    {
        return json_encode(['pickup_time' => $time, 'pickup_time_range_id' => $range, 'pickup_time_range' => $range === 0 ? 'Now' : '09:00-12:00']);
    }

    public function test_spx_pickup_slot_must_be_a_future_courier_slot(): void
    {
        $this->accounts();
        $pickup = fn (string $slot) => $this->form('spx', ['handover' => 'pickup', 'pickup_slot' => $slot]);

        foreach ([
            'x',
            '"2026-11-20"',
            json_encode(['pickup_time' => now()->addDay()->timestamp]),
            json_encode(['pickup_time' => 'soon', 'pickup_time_range_id' => 0, 'pickup_time_range' => 'Now']),
            $this->slot(now()->subHour()->timestamp),
        ] as $bad) {
            $this->post('/shipments', $pickup($bad))->assertSessionHasErrors(['pickup_slot' => 'Choose a time.']);
        }
        $this->assertSame(0, Shipment::query()->count());

        $this->post('/shipments', $pickup($this->slot(now()->addHour()->timestamp)))->assertSessionHasNoErrors();
    }

    /** A manual shipment the courier refused, so it can be edited. */
    private function failed(string $courier, array $form = []): Shipment
    {
        $this->fakes[$courier]->refusesWith('No');
        $this->post('/shipments', $this->form($courier, $form))->assertSessionHasNoErrors();
        $s = $this->latest();
        $this->assertSame('failed', $s->state);

        return $s;
    }

    public function test_edit_with_past_spx_slot_shows_load_times(): void
    {
        $this->accounts();
        $future = $this->slot(now()->addDay()->timestamp);
        $s = $this->failed('spx', ['handover' => 'pickup', 'pickup_slot' => $future]);
        $idle = '/class="sh-slots__row" x-show="slotState !== \'ready\'"\s*>/';

        $html = $this->get(route('shipments.edit', $s->public_id))->assertOk()->getContent();
        $this->assertDoesNotMatchRegularExpression($idle, $html);

        $s->forceFill(['options' => ['pickup_slot' => $this->slot(now()->subDay()->timestamp)] + $s->options])->save();
        $html = $this->get(route('shipments.edit', $s->public_id))->assertOk()->getContent();
        $this->assertMatchesRegularExpression($idle, $html);
        $this->assertStringNotContainsString('pickup_time_range_id', $html);
    }

    public function test_legacy_quadx_shipment_without_parcel_type_edits(): void
    {
        $this->accounts();
        $s = $this->failed('quadx');
        $s->forceFill(['options' => ['insure' => false]])->save();

        $html = $this->get(route('shipments.edit', $s->public_id))->assertOk()->getContent();
        $this->assertDoesNotMatchRegularExpression('/name="parcel\[type\]"[^>]*checked/', $html);

        $this->put(route('shipments.update', $s->public_id), $this->form('quadx', ['parcel' => ['type' => '']]))
            ->assertSessionHasErrors(['parcel.type' => 'Choose the packaging.']);
        $this->assertArrayNotHasKey('parcel', $s->refresh()->options);

        $this->put(route('shipments.update', $s->public_id), $this->form('quadx'))->assertSessionHasNoErrors();
        $this->assertSame('box', $s->refresh()->options['parcel']['type']);
    }

    public function test_spx_inline_limits_and_method_follows_handover(): void
    {
        $this->accounts();
        $cod = ['payment' => ['method' => 'cod']];
        $r = $this->post('/shipments', $this->form('spx', $cod + ['value' => '1290.50']));
        $this->assertSame('SPX collects whole pesos only.', session('errors')->first('value'));
        $this->post('/shipments', $this->form('spx', $cod + ['value' => '50001']));
        $this->assertSame('SPX collects up to ₱50,000.', session('errors')->first('value'));
        $this->post('/shipments', $this->form('spx', ['value' => '1290.50']))->assertSessionHasNoErrors();
        $this->post('/shipments', $this->form('spx', ['parcel' => ['weight_kg' => '50']]));
        $this->assertSame('Under 50 kg.', session('errors')->first('parcel.weight_kg'));
        $this->post('/shipments', $this->form('spx', ['parcel' => ['type' => 'custom', 'length_cm' => '151', 'width_cm' => '10', 'height_cm' => '10']]));
        $this->assertSame('150 cm at most.', session('errors')->first('parcel.length_cm'));

        $this->assertSame('dropoff', $this->latest()->method);
        $this->post('/shipments', $this->form('spx', ['handover' => 'pickup', 'pickup_slot' => $this->slot(time() + 86400)]))->assertSessionHasNoErrors();
        $this->assertSame('pickup', $this->latest()->method);
    }

    public function test_edit_with_stale_lbc_date_selects_nothing(): void
    {
        $this->accounts();
        $s = $this->failed('lbc');
        $o = $s->options;
        $o['pickup_date'] = '2020-01-01';
        $s->update(['options' => $o]);

        $this->get(route('shipments.edit', $s->public_id))->assertOk()->assertSee('Choose a date.');
    }

    public function test_lbc_dates_are_next_business_days(): void
    {
        $this->accounts();
        $this->travelTo(CarbonImmutable::parse('2026-11-14 10:00', 'Asia/Manila')); // a Saturday

        $expected = ['2026-11-16', '2026-11-17', '2026-11-18', '2026-11-19', '2026-11-20', '2026-11-21',
            '2026-11-23', '2026-11-24', '2026-11-25', '2026-11-26'];
        $this->assertSame($expected, $this->lbcDates());
        $this->getJson('/shipments/options/lbc-dates')->assertOk()->assertJsonPath('dates.0.value', '2026-11-16')
            ->assertJsonPath('dates.9.value', '2026-11-26')->assertJsonCount(10, 'dates');

        $this->post('/shipments', $this->form('lbc', ['pickup_date' => '2026-11-22']))->assertSessionHasErrors('pickup_date');
        $this->post('/shipments', $this->form('lbc', ['pickup_date' => '2026-11-14']))->assertSessionHasErrors('pickup_date');
        $this->post('/shipments', $this->form('lbc', ['pickup_date' => '']))->assertSessionHasErrors('pickup_date');
        $this->post('/shipments', $this->form('lbc', ['pickup_date' => '2026-11-21']))->assertSessionHasNoErrors();
        $this->assertSame('2026-11-21', $this->latest()->options['pickup_date']);
        $this->assertSame('2026-11-21', $this->fakes['lbc']->callsTo('createShipment')[0]['args'][1]['pickup_date']);
    }

    public function test_parcel_type_must_be_courier_option(): void
    {
        $this->accounts();

        $this->post('/shipments', $this->form('quadx', ['parcel' => ['type' => '16']]))->assertSessionHasErrors('parcel.type');
        $this->post('/shipments', $this->form('spx', ['parcel' => ['type' => 'box']]))->assertSessionHasErrors('parcel.type');
        $this->post('/shipments', $this->form('lbc', ['parcel' => ['type' => 'small-pouch']]))->assertSessionHasErrors('parcel.type');
        $this->post('/shipments', $this->form('lbc', ['parcel' => ['type' => '']]))->assertSessionHasErrors('parcel.type');
        $this->assertSame(0, Shipment::query()->count());

        $this->post('/shipments', $this->form('lbc', ['parcel' => ['type' => '80']]))->assertSessionHasNoErrors();
        $this->assertSame('80', $this->latest()->options['parcel']['type']);
    }

    public function test_ui_has_no_email_or_unit_fields(): void
    {
        $this->accounts();

        $html = $this->get('/shipments/new')->assertOk()->getContent();
        foreach (['recipient[email]', 'recipient[address_2]', 'pickup[address_2]', 'parcel[type]" type="text'] as $name) {
            $this->assertStringNotContainsString($name, $html, $name);
        }
        $this->assertStringNotContainsString('Unit / building', $html);

        // Edit: an existing address_2 and email are not shown as fields; the unit joins the street line.
        $s = $this->failed('quadx');
        $s->forceFill([
            'recipient' => ['address_2' => 'Unit 4B, Sun Tower', 'email' => 'juan@example.com'] + (array) $s->recipient,
            'pickup' => ['address_2' => 'Bldg 9'] + (array) $s->pickup,
        ])->save();
        $html = $this->get(route('shipments.edit', $s->public_id))->assertOk()->getContent();
        $this->assertStringContainsString('1 Rizal St, Unit 4B, Sun Tower', $html);
        $this->assertStringContainsString('5 Shaw Blvd, Bldg 9', $html);
        $this->assertStringNotContainsString('juan@example.com', $html);
        $this->assertStringNotContainsString('name="recipient[address_2]"', $html);
    }
}
