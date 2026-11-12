<?php

namespace Tests\Feature\Ui;

use App\Models\Admin;
use App\Models\AuditEntry;
use App\Models\ClientApp;
use App\Models\CourierAccount;
use App\Models\Shipment;
use App\Models\ShipmentLabel;
use App\Shipments\SpxBookingEdits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsCourierAccounts;
use Tests\TestCase;

/** Edit booking: SPX 1.7 Update Order, only HTTP faked. Nothing here reaches SPX. */
class SpxBookingEditTest extends TestCase
{
    use BuildsCourierAccounts, RefreshDatabase;

    private const TRACKING = 'SPEPH00000001234B';

    private CourierAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Storage::fake('local');
        $this->account = $this->spxAccount();
        $this->actingAs(Admin::query()->create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'correct-horse-battery']));
    }

    private function booked(array $attributes = []): Shipment
    {
        return Shipment::factory()->create($attributes + [
            'client_app_id' => ClientApp::factory(),
            'courier_account_id' => $this->account->id,
            'courier' => 'spx',
            'state' => 'booked',
            'courier_status' => '1001',
            'tracking_number' => self::TRACKING,
            'client_reference' => '29850',
            'active_reference' => '29850',
            'courier_ref' => '20261112-7K2M9Q',
            'booked_at' => now()->subHour(),
            'method' => 'dropoff',
            'payment_method' => 'cod',
            'payment_state' => 'cod_unpaid',
            'cod_amount' => '1250.00',
            'total' => '1250.00',
            'pickup' => [
                'contact_name' => 'Juan Shop', 'contact_phone' => '09181234567', 'address_1' => '5 Shaw Blvd',
                'barangay' => 'Wack-wack Greenhills', 'city' => 'Mandaluyong', 'state' => 'Metro Manila', 'postcode' => '1550',
                'country' => 'PH', 'codes' => [],
            ],
            'recipient' => [
                'first_name' => 'Jane', 'last_name' => 'Doe', 'phone' => '09171234567', 'email' => 'jane@example.com',
                'address_1' => '123 Mango St', 'barangay' => 'Lahug (Pob.)', 'city' => 'Cebu City', 'state' => 'Cebu',
                'postcode' => '6000', 'country' => 'PH', 'codes' => [],
            ],
            'items' => [['name' => 'Blue Tee', 'quantity' => 1, 'price' => null]],
            'options' => ['parcel' => ['type' => 'small', 'weight_kg' => '1', 'length_cm' => '25', 'width_cm' => '20', 'height_cm' => '10', 'category' => 'Clothing'],
                'shipping_payment' => 'receiver_pay', 'insure' => false],
            'meta' => ['batch_no' => 9001],
        ]);
    }

    private function form(array $over = []): array
    {
        return array_replace_recursive([
            'recipient' => ['first_name' => 'Janet', 'last_name' => 'Doe', 'phone' => '0917 765 4321',
                'address_1' => '45 Mango St', 'barangay' => 'Lahug (Pob.)', 'city' => 'Cebu City', 'state' => 'Cebu', 'postcode' => '6000'],
            'item' => 'Red Tee',
            'parcel' => ['type' => 'medium', 'weight_kg' => '2', 'length_cm' => '25', 'width_cm' => '35', 'height_cm' => '15'],
            'note' => 'Gate 2',
            // Locked: ignored whatever is posted.
            'payment' => ['method' => 'prepaid', 'shipping_payment' => 'sender_pay'],
            'value' => '1',
            'handover' => 'pickup',
        ], $over);
    }

    private function updated(): array
    {
        return ['ret_code' => 0, 'message' => 'success.', 'data' => ['orders' => [[
            'order_id' => '20261112-7K2M9Q', 'tracking_no' => self::TRACKING, 'tracking_link' => 'https://uat.spx.ph/track?'.self::TRACKING,
        ]], 'fail_list' => []]];
    }

    public function test_the_edit_button_shows_only_for_a_pending_pickup_spx_booking_with_edits_left(): void
    {
        $s = $this->booked();
        $html = $this->get(route('shipments.show', $s->public_id))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#Print waybill</button>\s*<a href="'.preg_quote(route('shipments.booking.edit', $s->public_id), '#').'"\s+class="x-btn x-btn--default x-btn--md sd-edit-booking">Edit</a>\s*<span class="sd-actions__sep"#', $html);
        $this->assertStringContainsString('3 edits left', $html);

        $hidden = [
            'picked up' => ['courier_status' => '2001'],
            'not booked' => ['state' => 'draft', 'tracking_number' => null],
            'cancel requested' => ['cancel_state' => 'requested'],
            'three edits made' => ['meta' => ['booking_updates' => [['at' => 'a'], ['at' => 'b'], ['at' => 'c']]]],
        ];
        foreach ($hidden as $why => $attributes) {
            $other = $this->booked($attributes + ['tracking_number' => 'SPEPH0000000'.random_int(10000, 99999), 'courier_ref' => '20261112-'.strtoupper(substr(md5($why), 0, 6))]);
            $page = $this->get(route('shipments.show', $other->public_id))->getContent();
            $this->assertStringNotContainsString('sd-edit-booking', $page, $why);
            $this->assertStringNotContainsString('edits left', $page, $why);
            $this->get(route('shipments.booking.edit', $other->public_id))->assertRedirect(route('shipments.show', $other->public_id));
        }

        $quadx = CourierAccount::factory()->create(['courier' => 'quadx']);
        $q = $this->booked(['courier' => 'quadx', 'courier_account_id' => $quadx->id, 'tracking_number' => 'QX-1', 'courier_ref' => '20261112-QQQQQQ']);
        $this->assertStringNotContainsString('sd-edit-booking', $this->get(route('shipments.show', $q->public_id))->getContent());

        $one = $this->booked(['tracking_number' => 'SPEPH000000099', 'courier_ref' => '20261112-ONEONE', 'meta' => ['booking_updates' => [['at' => 'a'], ['at' => 'b']]]]);
        $this->get(route('shipments.show', $one->public_id))->assertSee('1 edit left');
    }

    public function test_the_edit_page_shows_what_can_change_and_the_rest_read_only(): void
    {
        $s = $this->booked();
        $html = $this->get(route('shipments.booking.edit', $s->public_id))->assertOk()->getContent();

        $this->assertStringContainsString('Edit booking', $html);
        $this->assertStringContainsString('3 edits left', $html);
        foreach (['recipient[first_name]', 'recipient[phone]', 'recipient[address_1]', 'item', 'parcel[type]', 'parcel[weight_kg]', 'parcel[length_cm]', 'note'] as $field) {
            $this->assertStringContainsString('name="'.$field.'"', $html, $field);
        }
        $this->assertStringNotContainsString('name="pickup_slot"', $html, 'a drop-off has no pickup time');
        $this->assertStringNotContainsString('name="payment[method]"', $html);
        $this->assertMatchesRegularExpression('#<dt>Handover</dt><dd>Drop off myself</dd>.*<dt>Shipping fee</dt><dd>Receiver pays</dd>.*<dt>COD</dt><dd>₱1,250.00</dd>.*<dt>Insurance</dt><dd>No</dd>#s', $html);
        $this->assertStringContainsString('Save changes', $html);
    }

    public function test_a_successful_update_sends_the_whole_order_and_stores_the_changes(): void
    {
        Http::fake(['*batch_update_order*' => Http::response($this->updated())]);
        $s = $this->booked();
        Storage::disk('local')->put('labels/spx/'.self::TRACKING.'.pdf', '%PDF old');
        ShipmentLabel::query()->create(['shipment_id' => $s->id, 'format' => 'pdf', 'path' => 'labels/spx/'.self::TRACKING.'.pdf', 'bytes' => 8, 'sha256' => hash('sha256', '%PDF old'), 'fetched_at' => now()]);

        $this->put(route('shipments.booking.update', $s->public_id), $this->form())
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('shipments.show', $s->public_id))
            ->assertSessionHas('booking_updated', true);

        Http::assertSentCount(1);
        Http::assertSent(function (HttpRequest $r) {
            $o = $r['orders'][0];

            return str_ends_with($r->url(), '/open/api/v1/order/batch_update_order')
                && $o['tracking_no'] === self::TRACKING
                && $o['order_id'] === '20261112-7K2M9Q'
                && $o['base_info'] === ['service_type' => 1]
                // Unchanged: drop-off, receiver pays, the booked COD.
                && $o['fulfillment_info']['collect_type'] === 2
                && $o['fulfillment_info']['payment_role'] === 2
                && $o['fulfillment_info']['cod_collection'] === 1
                && $o['fulfillment_info']['cod_amount'] === 1250
                // Changed.
                && $o['deliver_info']['deliver_name'] === 'Janet Doe'
                && $o['deliver_info']['deliver_instruction'] === 'Gate 2'
                && (float) $o['parcel_info']['parcel_weight'] === 2.0
                && (float) $o['parcel_info']['parcel_width'] === 35.0
                && $o['parcel_info']['parcel_item_name'] === 'Red Tee'
                && $o['parcel_info']['parcel_item_type'] === 'Clothing';
        });

        $s->refresh();
        $this->assertSame('Janet', $s->recipient['first_name']);
        $this->assertSame('09177654321', $s->recipient['phone']);
        $this->assertSame('jane@example.com', $s->recipient['email'], 'the stored email stays');
        $this->assertSame('medium', $s->options['parcel']['type']);
        $this->assertSame('Clothing', $s->options['parcel']['category']);
        $this->assertSame('receiver_pay', $s->options['shipping_payment']);
        $this->assertSame('Gate 2', $s->courier_note);
        $this->assertSame('Red Tee', $s->items[0]['name']);
        $this->assertSame('cod', $s->payment_method);
        $this->assertSame('1250.00', $s->cod_amount);
        $this->assertSame('dropoff', $s->method);
        $this->assertSame(1, SpxBookingEdits::made($s));
        $this->assertSame(9001, $s->meta['batch_no']);
        $this->assertSame(0, ShipmentLabel::query()->count());
        Storage::disk('local')->assertMissing('labels/spx/'.self::TRACKING.'.pdf');
        $this->assertSame(['recipient', 'options', 'courier_note', 'items'], AuditEntry::query()->where('action', 'shipment.booking_updated')->sole()->changes);

        $html = $this->get(route('shipments.show', $s->public_id))->getContent();
        $this->assertStringContainsString('Booking updated', $html);
        $this->assertMatchesRegularExpression('#<button type="submit"\s+class="x-btn x-btn--primary x-btn--md" form="sd-labels">Print new waybill</button>#', $html);
        $this->assertStringContainsString('2 edits left', $html);
        $this->assertMatchesRegularExpression('#<span class="sd-event__label">Booking updated</span>#', $html);
        $this->get(route('shipments.show', $s->public_id))->assertDontSee('Print new waybill');
    }

    public function test_a_pickup_booking_sends_the_new_pickup_time(): void
    {
        Http::fake(['*batch_update_order*' => Http::response($this->updated())]);
        $old = json_encode(['pickup_time' => now()->addDay()->getTimestamp(), 'pickup_time_range_id' => 1, 'pickup_time_range' => '9:00am-12:00pm']);
        $new = json_encode(['pickup_time' => now()->addDays(2)->getTimestamp(), 'pickup_time_range_id' => 2, 'pickup_time_range' => '1:00pm-6:00pm']);
        $s = $this->booked(['method' => 'pickup', 'options' => ['parcel' => ['type' => 'small', 'weight_kg' => '1', 'length_cm' => '25', 'width_cm' => '20', 'height_cm' => '10'], 'shipping_payment' => 'sender_pay', 'pickup_slot' => $old]]);

        $this->get(route('shipments.booking.edit', $s->public_id))->assertOk()->assertSee('name="pickup_slot"', false);
        $this->put(route('shipments.booking.update', $s->public_id), $this->form(['pickup_slot' => $new]))->assertSessionHasNoErrors();

        Http::assertSent(fn (HttpRequest $r) => $r['orders'][0]['fulfillment_info']['collect_type'] === 1
            && $r['orders'][0]['fulfillment_info']['pickup_time_range_id'] === 2);
        $this->assertSame($new, $s->refresh()->options['pickup_slot']);
    }

    public function test_a_refusal_shows_the_plain_reason_and_changes_nothing(): void
    {
        Http::fake(['*batch_update_order*' => Http::response(['ret_code' => 0, 'message' => 'success.', 'data' => ['orders' => [], 'fail_list' => [[
            'order_id' => '20261112-7K2M9Q', 'ret_code' => 13051, 'message' => 'check rule|7 |ruleName| MaxLengthExtremumRule failed, 35.000000 exceed max value 25.000000',
        ]]]])]);
        $s = $this->booked();
        Storage::disk('local')->put('labels/spx/'.self::TRACKING.'.pdf', '%PDF old');
        ShipmentLabel::query()->create(['shipment_id' => $s->id, 'format' => 'pdf', 'path' => 'labels/spx/'.self::TRACKING.'.pdf', 'bytes' => 8, 'sha256' => hash('sha256', '%PDF old'), 'fetched_at' => now()]);
        $before = $s->fresh()->only(['recipient', 'options', 'courier_note', 'items', 'meta']);

        $edit = route('shipments.booking.edit', $s->public_id);
        $this->from($edit)->put(route('shipments.booking.update', $s->public_id), $this->form(['parcel' => ['length_cm' => '35']]))
            ->assertRedirect($edit)
            ->assertSessionHasErrors(['booking' => 'Too long for SPX. Length is 35 cm. SPX takes up to 25 cm.']);

        $this->assertEquals($before, $s->fresh()->only(['recipient', 'options', 'courier_note', 'items', 'meta']));
        $this->assertSame(1, ShipmentLabel::query()->count());
        $this->assertSame(0, AuditEntry::query()->where('action', 'shipment.booking_updated')->count());
        $page = $this->get($edit)->getContent();
        $this->assertStringContainsString('Too long for SPX. Length is 35 cm. SPX takes up to 25 cm.', $page);
        $this->assertStringNotContainsString('ExtremumRule', $page);
    }

    public function test_the_third_edit_is_the_last(): void
    {
        Http::fake(['*batch_update_order*' => Http::response($this->updated())]);
        $s = $this->booked(['meta' => ['booking_updates' => [['at' => 'a'], ['at' => 'b']]]]);

        $this->put(route('shipments.booking.update', $s->public_id), $this->form())->assertSessionHasNoErrors();
        $this->assertSame(3, SpxBookingEdits::made($s->refresh()));

        $this->put(route('shipments.booking.update', $s->public_id), $this->form(['item' => 'Again']))
            ->assertRedirect(route('shipments.show', $s->public_id))
            ->assertSessionHasErrors('shipment');
        Http::assertSentCount(1);
        $this->assertSame('Red Tee', $s->refresh()->items[0]['name']);
        $this->assertStringNotContainsString('sd-edit-booking', $this->get(route('shipments.show', $s->public_id))->getContent());
    }
}
