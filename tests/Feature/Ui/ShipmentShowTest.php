<?php

namespace Tests\Feature\Ui;

use App\Couriers\CourierOrder;
use App\Couriers\CourierRegistry;
use App\Couriers\FakeCourier;
use App\Fulfillment\ShipmentBadges;
use App\Models\Admin;
use App\Models\ClientApp;
use App\Models\CourierAccount;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Shipments\BookingOutcomes;
use App\Shipments\ShipmentCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Js;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Tests\Concerns\BuildsShipments;
use Tests\TestCase;

class ShipmentShowTest extends TestCase
{
    use BuildsShipments, RefreshDatabase;

    private CourierAccount $account;

    private ClientApp $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(Admin::query()->create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'correct-horse-battery']));
        $this->account = CourierAccount::factory()->create(['courier' => 'quadx', 'status' => 'active', 'label' => 'Main']);
        $this->client = ClientApp::factory()->create(['name' => 'VentaCart']);
        CourierRegistry::fake('quadx', FakeCourier::make('quadx'));
    }

    private function ship(string $state, array $over = []): Shipment
    {
        return Shipment::factory()->create($over + [
            'client_app_id' => $this->client->id,
            'courier_account_id' => $this->account->id,
            'state' => $state,
            'tracking_number' => in_array($state, ['booked', 'cancelled'], true) ? 'QX-'.uniqid() : null,
            'courier_ref' => 'VS-'.uniqid(),
            'recipient' => ['first_name' => 'Maria', 'last_name' => 'Cruz', 'phone' => '09171234567', 'address_1' => '12 Rizal St', 'city' => 'Makati', 'state' => 'Metro Manila', 'postcode' => '1200'],
        ]);
    }

    public function test_show_renders_sections_and_timeline_newest_first(): void
    {
        $s = $this->ship('booked', [
            'payment_method' => 'cod', 'payment_state' => 'cod_unpaid', 'cod_amount' => '1240.50', 'declared_value' => '900.00',
            'options' => ['insure' => true, 'parcel' => ['weight_kg' => 1.5]],
            'booked_at' => '2026-11-08 14:35:00',
            'needs_attention' => true, 'attention_reason' => 'Address unclear',
        ]);
        ShipmentEvent::query()->create(['shipment_id' => $s->id, 'source' => 'poll', 'courier_status' => 'picked_up', 'label' => 'Picked up zzz', 'phase' => 'in_transit', 'occurred_at' => '2026-11-08 13:00:00']);
        ShipmentEvent::query()->create(['shipment_id' => $s->id, 'source' => 'poll', 'courier_status' => 'out_for_delivery', 'label' => 'Out for delivery yyy', 'phase' => 'in_transit', 'occurred_at' => '2026-11-08 17:00:00']);

        $res = $this->get(route('shipments.show', $s->public_id))->assertOk();
        $res->assertSeeInOrder(['Customer', 'Parcel', 'Payment', 'Courier', 'Timeline']);
        $this->assertStringNotContainsString('>Source<', $res->getContent());
        $this->assertStringNotContainsString('Payment state', $res->getContent());
        $res->assertSee('Maria Cruz')->assertSee('12 Rizal St')->assertSee('0917 123 4567')
            ->assertSee($s->courier_ref)->assertSee($s->client_reference)
            ->assertSee('Blue Tee')->assertSee('₱1,240.50')->assertSee('₱900.00')
            ->assertSee('COD · Unpaid')->assertSee('Address unclear')
            ->assertSee('VentaCart')->assertSee($s->tracking_number)
            ->assertSee('Nov 8, 2026 · 2:35 PM', false);
        $res->assertSeeInOrder(['Out for delivery yyy', 'Picked up zzz']);
        $res->assertSee('Nov 8, 2026 · 1:00 PM', false);
    }

    public function test_show_lists_the_items_joined_on_one_line(): void
    {
        $s = $this->ship('booked', ['items' => [['name' => 'Shirt', 'quantity' => 2, 'price' => '10.00'], ['name' => 'Cap', 'quantity' => 1]]]);
        $this->get(route('shipments.show', $s->public_id))->assertOk()
            ->assertSeeInOrder(['Parcel', 'Item', '2 × Shirt, 1 × Cap', 'Value'], false);

        $one = $this->ship('booked', ['items' => [['name' => 'Assorted goods', 'quantity' => 1]]]);
        $html = $this->get(route('shipments.show', $one->public_id))->getContent();
        $this->assertMatchesRegularExpression('~<dt>Item</dt>\s*<dd>Assorted goods</dd>~', $html);
    }

    public function test_status_flash_renders(): void
    {
        $s = $this->ship('booked');
        $this->withSession(['status' => 'Booked · QX-1'])->get(route('shipments.show', $s->public_id))->assertSee('Booked · QX-1');
    }

    public function test_purged_shipment_shows_removed(): void
    {
        $s = $this->ship('cancelled', ['recipient' => null, 'pii_purged_at' => now()]);
        $res = $this->get(route('shipments.show', $s->public_id))->assertOk();
        $html = $res->getContent();
        $this->assertMatchesRegularExpression('#<dt>Name</dt><dd class="is-removed">Removed</dd>#', $html);
        $this->assertStringNotContainsString('<dt>Phone</dt>', $html);
        $this->assertStringNotContainsString('sd-retry', $html);
    }

    public function test_actions_visibility_by_state_and_source(): void
    {
        Route::middleware(['web', 'auth'])->group(function () {
            Route::get('shipments/{shipment}/edit', fn () => 'x')->name('shipments.edit');
            Route::delete('shipments/{shipment}', fn () => 'x')->name('shipments.destroy');
        });
        app('router')->getRoutes()->refreshNameLookups();

        $has = fn ($res, string $cls) => str_contains($res->getContent(), $cls);

        $s = $this->ship('booked');
        $booked = $this->get(route('shipments.show', $s->public_id));
        $this->assertTrue($has($booked, 'sd-print') && $has($booked, 'sd-cancel'));
        $this->assertFalse($has($booked, 'sd-edit') || $has($booked, 'sd-delete') || $has($booked, 'sd-book'));
        $booked->assertSee('Cancel shipment');
        $this->assertMatchesRegularExpression('#<form id="sd-cancel"[^>]*'.preg_quote(route('shipments.cancel'), '#').'.*?name="ids\[\]" value="'.$s->public_id.'"#s', $booked->getContent());
        $this->assertMatchesRegularExpression('#<form id="sd-labels"[^>]*'.preg_quote(route('shipments.labels'), '#').'.*?name="ids\[\]" value="'.$s->public_id.'"#s', $booked->getContent());

        $failed = $this->get(route('shipments.show', $this->ship('failed', ['source' => 'manual'])->public_id));
        $this->assertTrue($has($failed, 'sd-edit') && $has($failed, 'sd-delete'));
        $this->assertFalse($has($failed, 'sd-print') || $has($failed, 'sd-book') || $has($failed, 'sd-cancel'));

        $d = $this->ship('draft', ['source' => 'api']);
        $draft = $this->get(route('shipments.show', $d->public_id));
        $this->assertTrue($has($draft, 'sd-book'));
        $this->assertFalse($has($draft, 'sd-edit') || $has($draft, 'sd-delete') || $has($draft, 'sd-print') || $has($draft, 'sd-cancel'));
        $this->assertMatchesRegularExpression('#<form id="sd-book"[^>]*'.preg_quote(route('shipments.book'), '#').'.*?name="ids\[\]" value="'.$d->public_id.'"#s', $draft->getContent());

        $manualDraft = $this->get(route('shipments.show', $this->ship('draft', ['source' => 'manual'])->public_id));
        $this->assertTrue($has($manualDraft, 'sd-book') && $has($manualDraft, 'sd-edit') && $has($manualDraft, 'sd-delete'));
    }

    public function test_non_http_tracking_url_renders_as_plain_text(): void
    {
        CourierRegistry::fake('zzz', new class extends FakeCourier
        {
            public function trackingLinks(CourierOrder $order): array
            {
                return [['url' => 'javascript:alert(1)']];
            }
        });
        $s = $this->ship('booked', ['tracking_number' => 'QX-PLAIN', 'courier' => 'zzz']);
        $html = $this->get(route('shipments.show', $s->public_id))->assertSee('QX-PLAIN')->getContent();
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('class="sd-link"', $html);
    }

    public function test_title_is_the_order_number_and_the_courier_reference_is_a_copyable_row(): void
    {
        $s = $this->ship('booked', ['client_reference' => 'ORDER-77', 'courier_ref' => '20261112-7K2M9Q']);
        $html = $this->get(route('shipments.show', $s->public_id))->assertSee('<title>ORDER-77 · Shipment', false)->getContent();

        $this->assertMatchesRegularExpression('#<dt>Courier reference</dt>\s*<dd class="sd-dd-copy">\s*<span class="sd-mono">20261112-7K2M9Q</span>#', $html);
        $this->assertStringContainsString('Copy courier reference', $html);

    }

    public function test_unknown_id_404(): void
    {
        $this->get('/shipments/shp_nope')->assertNotFound();
    }

    public function test_row_links_to_show(): void
    {
        $s = $this->ship('draft');
        $this->get(route('fulfillment'))->assertSee('href="'.route('shipments.show', $s->public_id).'"', false);
    }

    /** Detail-page Cancel appears exactly when the bulk Cancel (Canceller::request) would act on the shipment. */
    public function test_cancel_shows_for_unknown_and_spx_booking_like_the_bulk_cancel(): void
    {
        $quadx = FakeCourier::make('quadx')->throws(new \RuntimeException('timeout'));
        CourierRegistry::fake('quadx', $quadx);
        $spxAccount = CourierAccount::factory()->create(['courier' => 'spx', 'status' => 'active', 'label' => 'SPX main']);
        CourierRegistry::fake('spx', FakeCourier::make('spx')->booksAs('SPX-1', 'creating'));
        $creator = app(ShipmentCreator::class);

        $unknown = $creator->create($this->client, $this->shipmentBody(['client_reference' => 'U-1', 'account_id' => $this->account->public_id, 'courier' => null]), now: true);
        $booking = $creator->create($this->client, $this->shipmentBody(['client_reference' => 'B-1', 'account_id' => $spxAccount->public_id, 'courier' => null]), now: true);
        $this->assertSame('unknown', $unknown->state);
        $this->assertSame('booking', $booking->state);

        foreach ([$unknown, $booking] as $s) {
            $html = $this->get(route('shipments.show', $s->public_id))->assertOk()->getContent();
            $this->assertStringContainsString('sd-cancel', $html, $s->state);
            $this->assertMatchesRegularExpression('#<form id="sd-cancel"[^>]*>.*?name="ids\[\]" value="'.$s->public_id.'"#s', $html);

            // The server accepts it, and then the button is gone.
            $this->post(route('shipments.cancel'), ['ids' => [$s->public_id]]);
            $this->assertSame('requested', $s->refresh()->cancel_state);
            $this->assertStringNotContainsString('sd-cancel', $this->get(route('shipments.show', $s->public_id))->getContent());
        }
    }

    public function test_h1_is_the_reference_not_the_public_id(): void
    {
        $s = $this->ship('draft', ['client_reference' => '29851']);
        $html = $this->get(route('shipments.show', $s->public_id))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<h1 class="x-page__title">29851\s*<span class="sd-title-badge">#', $html);
        $this->assertDoesNotMatchRegularExpression('#<h1[^>]*>[^<]*'.preg_quote($s->public_id, '#').'#', $html);
        $this->assertStringContainsString('<title>29851 · Shipment', $html);
        // No empty heading, and the customer's name is not a heading.
        $this->assertDoesNotMatchRegularExpression('#<h2[^>]*>\s*</h2>#', $html);
        $this->assertStringNotContainsString('<h2 class="sd-name', $html);
        $this->assertMatchesRegularExpression('#<span class="sd-ref__k">Order</span>\s*<span class="sd-mono">'.$s->client_reference.'</span>#', $html);
        $this->assertMatchesRegularExpression('#<span class="sd-ref__k">From</span> <span>VentaCart</span>#', $html);
    }

    public function test_failed_shows_the_couriers_refusal_escaped(): void
    {
        $s = $this->ship('failed', ['error_code' => 'courier_refused', 'error_message' => 'Cutoff <b>10:00</b> only.']);
        $html = $this->get(route('shipments.show', $s->public_id))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#data-alert="failed".*?<p class="sd-alert__title">QuadX refused this booking</p>\s*<p class="sd-alert__msg">Cutoff &lt;b&gt;10:00&lt;/b&gt; only.</p>#s', $html);
        $this->assertStringNotContainsString('<b>10:00</b>', $html);
    }

    public function test_failed_for_another_reason_says_booking_failed_and_merges_the_attention_reason(): void
    {
        $s = $this->ship('failed', ['error_code' => 'account_misconfigured', 'error_message' => 'Account disabled.', 'needs_attention' => true, 'attention_reason' => 'Check the account']);
        $html = $this->get(route('shipments.show', $s->public_id))->getContent();

        $this->assertStringContainsString('<p class="sd-alert__title">Booking failed</p>', $html);
        $this->assertSame(1, substr_count($html, 'class="sd-alert '));
        $this->assertStringContainsString('<p class="sd-alert__msg">Account disabled.</p>', $html);
        $this->assertStringContainsString('<p class="sd-alert__msg">Check the account</p>', $html);
    }

    public function test_retry_shows_for_a_failed_api_shipment_only(): void
    {
        Route::middleware(['web', 'auth'])->group(function () {
            Route::get('shipments/{shipment}/edit', fn () => 'x')->name('shipments.edit');
            Route::delete('shipments/{shipment}', fn () => 'x')->name('shipments.destroy');
        });
        app('router')->getRoutes()->refreshNameLookups();

        $api = $this->ship('failed', ['error_code' => 'courier_refused', 'error_message' => 'No']);
        $html = $this->get(route('shipments.show', $api->public_id))->getContent();
        $this->assertStringContainsString('sd-retry', $html);
        $this->assertMatchesRegularExpression('#<form id="sd-retry" method="POST" action="'.preg_quote(route('shipments.retry', $api->public_id), '#').'"#', $html);

        $manual = $this->get(route('shipments.show', $this->ship('failed', ['source' => 'manual'])->public_id))->getContent();
        $this->assertStringContainsString('sd-edit', $manual);
        $this->assertStringNotContainsString('sd-retry', $manual);

        foreach (['booked', 'unknown', 'draft', 'cancelled'] as $state) {
            $this->assertStringNotContainsString('sd-retry', $this->get(route('shipments.show', $this->ship($state)->public_id))->getContent(), $state);
        }
        $cancelled = $this->ship('failed', ['cancel_state' => 'cancelled']);
        $this->assertStringNotContainsString('sd-retry', $this->get(route('shipments.show', $cancelled->public_id))->getContent());
    }

    public function test_unknown_shows_the_resolve_form(): void
    {
        $s = $this->ship('unknown', ['booking_started_at' => now()->subHours(2), 'needs_attention' => true, 'attention_reason' => BookingOutcomes::UNKNOWN_REASON]);
        $html = $this->get(route('shipments.show', $s->public_id))->assertOk()->getContent();

        $this->assertStringContainsString('QuadX didn&#039;t confirm this booking · 2 hours ago', $html);
        $this->assertStringNotContainsString(e(BookingOutcomes::UNKNOWN_REASON), $html);
        $this->assertMatchesRegularExpression('#<form method="POST" action="'.preg_quote(route('shipments.resolve', $s->public_id), '#').'" class="sd-resolve"#', $html);
        $this->assertMatchesRegularExpression('#<input type="text" id="sd-tracking" name="tracking"[^>]*aria-label="Tracking number from QuadX"#s', $html);
        $this->assertStringContainsString('Courier has it', $html);
        $this->assertStringContainsString((string) Js::from(['title' => 'Mark '.$s->client_reference.' as not booked?', 'confirm' => 'Not booked', 'dismiss' => 'Back', 'form' => 'sd-not-booked']), $html);
        $this->assertMatchesRegularExpression('#<form id="sd-not-booked" method="POST" action="'.preg_quote(route('shipments.resolve', $s->public_id), '#').'">.*?name="outcome" value="not_booked"#s', $html);
        $this->assertStringContainsString('sd-cancel', $html);

        // A queued booking shows only its badge.
        $queued = $this->get(route('shipments.show', $this->ship('queued')->public_id))->getContent();
        $this->assertStringNotContainsString('class="sd-alert ', $queued);
    }

    public function test_tracking_error_shows_under_the_field(): void
    {
        $s = $this->ship('unknown');
        $html = $this->withSession(['errors' => (new ViewErrorBag)->put('default', new MessageBag(['tracking' => ['Enter the tracking number.']]))])
            ->get(route('shipments.show', $s->public_id))->getContent();

        $this->assertMatchesRegularExpression('#id="sd-tracking"[^>]*aria-invalid="true"\s+aria-describedby="sd-tracking-error"#s', $html);
        $this->assertSame(1, substr_count($html, 'Enter the tracking number.'));
    }

    public function test_rows_without_a_value_are_hidden(): void
    {
        $s = $this->ship('booked', [
            'recipient' => ['first_name' => 'Maria', 'last_name' => 'Cruz'],
            'service' => null, 'fee_amount' => null, 'booked_at' => null, 'courier_note' => null, 'items' => [],
            'declared_value' => null, 'options' => [],
        ]);
        $html = $this->get(route('shipments.show', $s->public_id))->getContent();

        foreach (['Phone', 'Address', 'Note', 'Service', 'Booked at', 'Fee', 'Item', 'Packaging', 'Weight', 'Size', 'Declared value', 'COD amount', 'Pickup'] as $row) {
            $this->assertStringNotContainsString("<dt>{$row}</dt>", $html, $row);
        }
        $this->assertStringNotContainsString('>—<', $html);
        $this->assertStringContainsString('<dt>Name</dt><dd>Maria Cruz</dd>', $html);
        $this->assertStringContainsString('<dt>Tracking</dt>', $html);
    }

    public function test_courier_row_does_not_repeat_the_courier_name(): void
    {
        $this->account->forceFill(['label' => 'QuadX', 'mode' => 'sandbox'])->save();
        $html = $this->get(route('shipments.show', $this->ship('booked')->public_id))->getContent();
        $this->assertStringContainsString('<dt>Courier</dt>', $html);
        $this->assertStringContainsString('<dd>QuadX · Sandbox</dd>', $html);
        $this->assertStringNotContainsString('QuadX · QuadX', $html);

        $this->account->forceFill(['label' => 'Main'])->save();
        $this->assertStringContainsString('<dd>QuadX · Main · Sandbox</dd>', $this->get(route('shipments.show', $this->ship('booked')->public_id))->getContent());
    }

    public function test_cod_without_a_payment_state_reads_cod_unpaid_here_and_in_the_list(): void
    {
        $cod = $this->ship('booked', ['payment_method' => 'cod', 'payment_state' => null, 'cod_amount' => '750.00']);
        $prepaid = $this->ship('booked', ['payment_method' => 'prepaid', 'payment_state' => null]);

        $this->assertSame(['COD · Unpaid', 'attn'], ShipmentBadges::payment($cod));
        $this->assertSame(['Prepaid', 'mute'], ShipmentBadges::payment($prepaid));
        $this->assertSame(['COD · Collected', 'cool'], ShipmentBadges::payment($this->ship('booked', ['payment_method' => 'cod', 'payment_state' => 'cod_collected'])));

        $this->get(route('shipments.show', $cod->public_id))->assertSee('COD · Unpaid')->assertSee('₱750.00');
        $this->get(route('shipments.show', $prepaid->public_id))->assertSee('Prepaid')->assertDontSee('COD · Unpaid');

        $list = $this->get(route('fulfillment'))->getContent();
        $this->assertMatchesRegularExpression('#data-id="'.$cod->public_id.'".*?₱750\.00</span>\s*<span class="fl-row__cod">COD</span>#s', $list);
    }

    public function test_timeline_shows_a_repeated_event_once_and_never_empty(): void
    {
        $s = $this->ship('booked', ['booked_at' => null]);
        foreach (['2026-11-08 13:00:00', '2026-11-08 13:00:20', '2026-11-08 13:00:40'] as $at) {
            ShipmentEvent::query()->create(['shipment_id' => $s->id, 'source' => 'poll', 'courier_status' => 'for_pickup', 'label' => 'Ready qqq', 'phase' => 'to_handover', 'occurred_at' => $at]);
        }
        ShipmentEvent::query()->create(['shipment_id' => $s->id, 'source' => 'poll', 'courier_status' => 'picked_up', 'label' => 'Picked up qqq', 'phase' => 'in_transit', 'occurred_at' => '2026-11-08 15:00:00']);
        ShipmentEvent::query()->create(['shipment_id' => $s->id, 'source' => 'poll', 'courier_status' => 'for_pickup', 'label' => 'Ready qqq', 'phase' => 'to_handover', 'occurred_at' => '2026-11-08 16:00:00']);

        $html = $this->get(route('shipments.show', $s->public_id))->getContent();
        $this->assertSame(2, substr_count($html, 'Ready qqq'), 'the 13:00 repeats show once; the later one is a new event');
        $this->assertSame(3, ShipmentEvent::query()->where('label', 'Ready qqq')->where('occurred_at', '<', '2026-11-08 14:00:00')->count(), 'nothing is deleted');
        $this->assertSeeInOrderHtml($html, ['Ready qqq', 'Picked up qqq', 'Ready qqq', 'Received from VentaCart']);

        $empty = $this->ship('queued');
        $html = $this->get(route('shipments.show', $empty->public_id))->getContent();
        $this->assertMatchesRegularExpression('#<li class="sd-event">\s*<span class="sd-event__time x-num">[^<]+</span>\s*<span class="sd-event__label">Received from VentaCart</span>#', $html);
        $this->assertStringNotContainsString('sd-none', $html);
    }

    public function test_the_header_has_a_back_link_and_cancel_shipment_set_apart_in_red(): void
    {
        $s = $this->ship('booked');
        $html = $this->get(route('shipments.show', $s->public_id))->assertOk()->getContent();

        // Back to the list, with the icon (never a text arrow).
        $this->assertMatchesRegularExpression('#<a class="x-back" href="'.preg_quote(route('fulfillment'), '#').'"><svg[^>]*>.*?</svg>\s*Shipments</a>#s', $html);
        // Cancel shipment is red-outlined, after a divider that sets it apart from Print waybill.
        $this->assertMatchesRegularExpression('#Print waybill</button>\s*<span class="sd-actions__sep" aria-hidden="true"></span>\s*<button type="button"\s+class="x-btn x-btn--danger-outline x-btn--md sd-cancel"[^>]*>Cancel shipment</button>#', $html);
        $this->assertDoesNotMatchRegularExpression('#>\s*Cancel\s*</button>#', $html);
        // The success bar belongs to the request right after a booking only.
        $this->assertStringNotContainsString('Shipment booked', $html);
        $this->withSession(['booked' => true])->get(route('shipments.show', $s->public_id))->assertSee('Shipment booked')->assertSee('Add another shipment');
    }

    public function test_cancel_and_delete_confirms_are_danger_and_the_dialog_is_accessible(): void
    {
        Route::middleware(['web', 'auth'])->group(function () {
            Route::get('shipments/{shipment}/edit', fn () => 'x')->name('shipments.edit');
            Route::delete('shipments/{shipment}', fn () => 'x')->name('shipments.destroy');
        });
        app('router')->getRoutes()->refreshNameLookups();

        $b = $this->ship('booked');
        $booked = $this->get(route('shipments.show', $b->public_id))->getContent();
        $this->assertStringContainsString((string) Js::from(['title' => 'Cancel shipment '.$b->client_reference.'?', 'confirm' => 'Cancel shipment', 'dismiss' => 'Keep shipment', 'form' => 'sd-cancel', 'tone' => 'danger']), $booked);
        $this->assertMatchesRegularExpression('#<div class="modal" role="dialog" aria-modal="true" aria-labelledby="x-confirm-title"[^>]*@keydown.tab="trap\(\$event\)"#s', $booked);
        $this->assertStringContainsString('<h2 class="modal__title" id="x-confirm-title" x-text="title">Confirm</h2>', $booked);
        $this->assertMatchesRegularExpression('#class="x-btn x-btn--default x-btn--md modal__safe" x-ref="safe"#', $booked);
        $this->assertStringContainsString("x-bind:class=\"tone === 'danger' ? 'x-btn--danger' : 'x-btn--primary'\"", $booked);

        $d = $this->ship('draft', ['source' => 'manual']);
        $draft = $this->get(route('shipments.show', $d->public_id))->getContent();
        $this->assertStringContainsString((string) Js::from(['title' => 'Delete shipment '.$d->client_reference.'?', 'confirm' => 'Delete shipment', 'dismiss' => 'Keep shipment', 'form' => 'sd-delete', 'tone' => 'danger']), $draft);
    }

    public function test_mobile_menu_button_is_wired_to_the_drawer(): void
    {
        $html = $this->get(route('shipments.show', $this->ship('booked')->public_id))->getContent();
        $this->assertMatchesRegularExpression('#<aside class="x-side" id="x-nav"#', $html);
        $this->assertMatchesRegularExpression('#class="x-menu" x-ref="navOpen" @click="openNav\(\)" aria-label="Open navigation" aria-controls="x-nav" aria-expanded="false" :aria-expanded="nav.toString\(\)"#', $html);
        $this->assertStringContainsString('x-ref="navClose"', $html);
    }

    /** @param  list<string>  $needles */
    private function assertSeeInOrderHtml(string $html, array $needles): void
    {
        $pos = 0;
        foreach ($needles as $n) {
            $found = strpos($html, $n, $pos);
            $this->assertNotFalse($found, "{$n} not found in order");
            $pos = $found + strlen($n);
        }
    }
}
