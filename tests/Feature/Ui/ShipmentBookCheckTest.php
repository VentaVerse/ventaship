<?php

namespace Tests\Feature\Ui;

use App\Couriers\CourierRegistry;
use App\Couriers\FakeCourier;
use App\Couriers\Support\CourierRefusal;
use App\Couriers\Support\FeeQuote;
use App\Models\Admin;
use App\Models\CourierAccount;
use App\Models\Shipment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsShipments;
use Tests\TestCase;

/**
 * Book shipment's final check: the check endpoint answers the courier's fee or its plain refusal without saving
 * anything, the booking itself checks again and saves nothing on a refusal, Save draft never asks, and the shipment
 * page shows its "Shipment booked" bar once, right after the booking.
 */
class ShipmentBookCheckTest extends TestCase
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
            'account_id' => $this->account->public_id,
            'recipient' => ['first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'phone' => '0917 123 4567',
                'address_1' => '1 Rizal St', 'barangay' => 'Poblacion', 'city' => 'Makati', 'state' => 'Metro Manila', 'postcode' => '1210'],
            'item' => 'Blue Tee',
            'value' => '1250',
            'parcel' => ['type' => 'box'],
            'payment' => ['method' => 'cod'],
            'pickup_address_id' => $this->pickupId(),
        ], $over);
    }

    private function tooLong(): FeeQuote
    {
        return FeeQuote::refused(new CourierRefusal('Too long for SPX', 'Length is 35 cm. SPX takes up to 25 cm.'), 'SPX 13051: MaxLengthExtremumRule failed');
    }

    public function test_check_answers_the_courier_fee_and_saves_nothing(): void
    {
        $this->fake->quotes(FeeQuote::fee('195'));

        $this->postJson(route('shipments.check'), $this->form())
            ->assertOk()
            ->assertExactJson(['fee' => '195.00', 'currency' => 'PHP', 'label' => '₱195.00']);

        $this->assertSame(0, Shipment::query()->count());
        $this->assertCount(1, $this->fake->callsTo('quoteFee'));
        $this->assertSame([], $this->fake->callsTo('createShipment'));
    }

    public function test_check_builds_the_courier_inputs_the_booking_would_send(): void
    {
        $this->fake->quotes(FeeQuote::fee('195'));

        $this->postJson(route('shipments.check'), $this->form())->assertOk();

        [$order, $options] = $this->fake->callsTo('quoteFee')[0]['args'];
        $this->assertSame('Juan', $order->shipping_first_name);
        $this->assertSame('Makati', $order->shipping_city);
        $this->assertTrue($order->cod);
        $this->assertSame(1250.0, $order->cod_amount);
        $this->assertSame('box', $options['parcel']);
        $this->assertSame('Mandaluyong', $options['pickup']->city);
    }

    public function test_check_answers_a_refusal_in_plain_words_without_the_courier_text(): void
    {
        $this->fake->quotes($this->tooLong());

        $res = $this->postJson(route('shipments.check'), $this->form())->assertOk();

        $res->assertExactJson([
            'error' => 'Too long for SPX. Length is 35 cm. SPX takes up to 25 cm.',
            'title' => 'Too long for SPX',
            'detail' => 'Length is 35 cm. SPX takes up to 25 cm.',
        ]);
        $this->assertStringNotContainsString('ExtremumRule', $res->getContent());
        $this->assertSame(0, Shipment::query()->count());
    }

    public function test_check_without_a_fee_answers_none_and_never_refuses(): void
    {
        $this->postJson(route('shipments.check'), $this->form())
            ->assertOk()
            ->assertExactJson(['fee' => null, 'currency' => null, 'label' => null]);
    }

    public function test_a_courier_exception_during_the_check_is_no_fee_not_a_refusal(): void
    {
        $this->fake->throws(new \RuntimeException('curl 28 https://secret.example'), 'quoteFee');

        $res = $this->postJson(route('shipments.check'), $this->form())->assertOk();

        $res->assertExactJson(['fee' => null, 'currency' => null, 'label' => null]);
        $this->assertStringNotContainsString('secret', $res->getContent());
    }

    public function test_check_validates_the_form_like_the_booking(): void
    {
        $this->postJson(route('shipments.check'), $this->form(['item' => '', 'recipient' => ['phone' => '']]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['item', 'recipient.phone']);

        $this->assertSame([], $this->fake->callsTo('quoteFee'));
    }

    public function test_check_for_an_account_that_cannot_book_is_a_field_error(): void
    {
        $this->account->forceFill(['status' => 'disabled'])->save();

        $this->postJson(route('shipments.check'), $this->form())
            ->assertStatus(422)
            ->assertJsonPath('errors.account_id.0', 'Not ready to book.');
    }

    public function test_check_needs_a_session_and_csrf(): void
    {
        auth()->logout();
        $this->postJson(route('shipments.check'), $this->form())->assertStatus(401);
    }

    public function test_book_runs_the_check_again_and_a_refusal_saves_nothing(): void
    {
        $this->fake->quotes($this->tooLong());
        $token = $this->token();

        $this->from('/shipments/new')->post('/shipments', $this->form(['form_token' => $token]))
            ->assertRedirect('/shipments/new')
            ->assertSessionHasErrors(['booking' => 'Too long for SPX. Length is 35 cm. SPX takes up to 25 cm.']);

        $this->assertSame(0, Shipment::query()->count());
        $this->assertSame([], $this->fake->callsTo('createShipment'));

        // The form shows the plain message, never the courier's own text.
        $html = $this->get('/shipments/new')->assertOk()->getContent();
        $this->assertStringContainsString('Too long for SPX. Length is 35 cm. SPX takes up to 25 cm.', $html);
        $this->assertStringNotContainsString('ExtremumRule', $html);

        // The token was not used: the corrected form books.
        $this->fake->quotes(FeeQuote::fee('195'));
        $this->post('/shipments', $this->form(['form_token' => $token]))->assertSessionHasNoErrors()->assertSessionHas('booked', true);
        $this->assertSame('booked', Shipment::query()->sole()->state);
    }

    public function test_book_goes_ahead_when_the_courier_gave_no_fee(): void
    {
        $this->post('/shipments', $this->form(['form_token' => $this->token()]))->assertSessionHasNoErrors();

        $this->assertSame('booked', Shipment::query()->sole()->state);
        $this->assertCount(1, $this->fake->callsTo('quoteFee'));
    }

    public function test_save_draft_never_runs_the_check(): void
    {
        $this->fake->quotes($this->tooLong());

        $this->post('/shipments', $this->form(['form_token' => $this->token(), 'draft' => '1']))->assertSessionHasNoErrors();

        $this->assertSame('draft', Shipment::query()->sole()->state);
        $this->assertSame([], $this->fake->callsTo('quoteFee'));
    }

    public function test_save_and_book_refused_by_the_check_leaves_the_draft_as_it_was(): void
    {
        $this->post('/shipments', $this->form(['form_token' => $this->token(), 'draft' => '1', 'item' => 'Old item']));
        $s = Shipment::query()->sole();
        $this->fake->quotes($this->tooLong());

        $edit = route('shipments.edit', $s->public_id);
        $this->from($edit)->put(route('shipments.update', $s->public_id), $this->form(['form_token' => $this->token($edit), 'book' => '1', 'item' => 'New item']))
            ->assertRedirect($edit)
            ->assertSessionHasErrors(['booking' => 'Too long for SPX. Length is 35 cm. SPX takes up to 25 cm.']);

        $s->refresh();
        $this->assertSame('draft', $s->state);
        $this->assertSame('Old item', $s->items[0]['name']);
        $this->assertSame([], $this->fake->callsTo('createShipment'));
    }

    public function test_edit_check_uses_its_own_route_and_refuses_a_shipment_that_cannot_change(): void
    {
        $this->post('/shipments', $this->form(['form_token' => $this->token(), 'draft' => '1']));
        $s = Shipment::query()->sole();
        $this->fake->quotes(FeeQuote::fee('88.5'));

        $this->postJson(route('shipments.check-edit', $s->public_id), $this->form())->assertOk()->assertJsonPath('label', '₱88.50');

        $s->forceFill(['state' => 'booked'])->save();
        $this->postJson(route('shipments.check-edit', $s->public_id), $this->form())->assertStatus(409)->assertJsonStructure(['error']);
    }

    public function test_the_forms_carry_the_final_check(): void
    {
        $html = $this->get('/shipments/new')->assertOk()->getContent();
        $this->assertStringContainsString('x-on:submit="submitted($event)"', $html);
        // The config as Js::from writes it: the check endpoint for a new shipment.
        $config = (string) \Illuminate\Support\Js::from(['checkUrl' => route('shipments.check')]);
        $this->assertStringContainsString(substr($config, strlen("JSON.parse('{"), -strlen("}')")), $html);
        $this->assertStringContainsString('Book this shipment?', $html);
        $this->assertStringContainsString('Back to edit', $html);

        // The dialog never labels a button Cancel, and starts hidden before Alpine runs.
        $dialog = substr($html, strpos($html, 'id="sh-book-title"'));
        $dialog = substr($dialog, 0, strpos($dialog, '</form>'));
        $this->assertDoesNotMatchRegularExpression('#>\s*Cancel\s*<#', $dialog);
        $this->assertMatchesRegularExpression('#<div class="modal-backdrop" x-show="check.open" x-cloak#', $html);
        $this->assertMatchesRegularExpression('#role="dialog" aria-modal="true" aria-labelledby="sh-book-title"#', $html);
    }

    public function test_after_booking_the_page_shows_the_booked_bar_once(): void
    {
        $this->post('/shipments', $this->form(['form_token' => $this->token()]))->assertSessionHas('booked', true);
        $s = Shipment::query()->sole();

        $html = $this->get(route('shipments.show', $s->public_id))->assertOk()->getContent();
        $this->assertStringContainsString('Shipment booked', $html);
        $this->assertMatchesRegularExpression('#<a href="'.preg_quote(route('fulfillment'), '#').'"\s+class="x-btn x-btn--default[^"]*">Go to shipments</a>#', $html);
        $this->assertMatchesRegularExpression('#<a href="'.preg_quote(route('shipments.create'), '#').'"\s+class="x-btn x-btn--primary[^"]*">Add another shipment</a>#', $html);

        $this->get(route('shipments.show', $s->public_id))->assertOk()->assertDontSee('Shipment booked');
    }

    private function draft(string $item = 'Blue Tee'): Shipment
    {
        $this->post('/shipments', $this->form(['form_token' => $this->token(), 'draft' => '1', 'item' => $item]));

        return Shipment::query()->latest('id')->first();
    }

    public function test_bulk_book_checks_each_draft_and_a_refusal_stays_a_draft(): void
    {
        $refused = $this->draft('Too long');
        $fine = $this->draft('Fits');
        $fake = new class extends FakeCourier
        {
            public ?int $refuse = null;

            public function quoteFee(\App\Couriers\CourierOrder $order, array $options): FeeQuote
            {
                parent::quoteFee($order, $options);

                return (int) $order->id === $this->refuse
                    ? FeeQuote::refused(new CourierRefusal('Too long for SPX', 'Length is 35 cm. SPX takes up to 25 cm.'))
                    : FeeQuote::fee('120');
            }
        };
        $fake->refuse = $refused->id;
        CourierRegistry::fake('quadx', $fake);

        $this->from('/')->post(route('shipments.book'), ['ids' => [$refused->public_id, $fine->public_id]])
            ->assertRedirect('/')
            ->assertSessionHas('status', '1 booked · 1 refused');

        $this->assertSame([$refused->courier_ref.': Too long for SPX. Length is 35 cm. SPX takes up to 25 cm.'], session('errors')->getBag('default')->all());
        $this->assertSame('draft', $refused->refresh()->state);
        $this->assertNull($refused->error_message);
        $this->assertSame('booked', $fine->refresh()->state);
        $this->assertCount(1, $fake->callsTo('createShipment'));
    }

    public function test_the_draft_page_book_opens_the_same_check(): void
    {
        $s = $this->draft();
        $html = $this->get(route('shipments.show', $s->public_id))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<button type="submit"\s+class="x-btn x-btn--primary x-btn--md sd-book" form="sd-book" data-book#', $html);
        $this->assertMatchesRegularExpression('#<form id="sd-book"[^>]*x-data="bookCheck\(#s', $html);
        $this->assertStringContainsString('Book this shipment?', $html);
        $config = (string) \Illuminate\Support\Js::from(['checkUrl' => route('shipments.check-draft', $s->public_id)]);
        $this->assertStringContainsString(substr($config, strlen("JSON.parse('{"), -strlen("}')")), $html);

        $this->fake->quotes($this->tooLong());
        $this->postJson(route('shipments.check-draft', $s->public_id))->assertOk()
            ->assertJsonPath('error', 'Too long for SPX. Length is 35 cm. SPX takes up to 25 cm.');
        $this->fake->quotes(FeeQuote::fee('120'));
        $this->postJson(route('shipments.check-draft', $s->public_id))->assertOk()->assertJsonPath('label', '₱120.00');

        $s->forceFill(['state' => 'booked'])->save();
        $this->postJson(route('shipments.check-draft', $s->public_id))->assertStatus(409)->assertJsonStructure(['error']);
    }

    public function test_the_draft_summary_rows(): void
    {
        $s = $this->draft();
        $rows = (new \App\Fulfillment\ShipmentDetail($s->load('courierAccount')))->bookingSummary();

        $this->assertSame(['Courier', 'To', 'Parcel', 'COD', 'Shipping fee'], array_column($rows, 0));
        $this->assertSame('Juan Dela Cruz · Makati, Metro Manila', $rows[1][1]);
        $this->assertSame('₱1,250.00', $rows[3][1]);
    }
}
