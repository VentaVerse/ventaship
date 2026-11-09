<?php

namespace Tests\Feature\Ui;

use App\Couriers\CourierRegistry;
use App\Couriers\FakeCourier;
use App\Models\Admin;
use App\Models\AuditEntry;
use App\Models\ClientApp;
use App\Models\CourierAccount;
use App\Models\OutgoingEvent;
use App\Models\Shipment;
use App\Shipments\BookingOutcomes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ShipmentActionsTest extends TestCase
{
    use RefreshDatabase;

    private Admin $admin;

    private CourierAccount $account;

    private FakeCourier $courier;

    private ClientApp $client;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->admin = Admin::query()->create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'correct-horse-battery']);
        $this->actingAs($this->admin);
        $this->account = CourierAccount::factory()->create(['courier' => 'quadx', 'status' => 'active']);
        $this->client = ClientApp::factory()->create();
        $this->courier = FakeCourier::make('quadx')->label($this->pdf('a'));
        CourierRegistry::fake('quadx', $this->courier);
    }

    protected function tearDown(): void
    {
        foreach (glob(storage_path('app/tmp/*')) ?: [] as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    private function ship(string $state, string $phase, array $over = []): Shipment
    {
        return Shipment::factory()->create($over + [
            'client_app_id' => $this->client->id,
            'courier_account_id' => $this->account->id,
            'state' => $state,
            'phase' => $phase,
            'tracking_number' => in_array($state, ['booked', 'cancelled'], true) ? 'QX-'.uniqid() : null,
            'courier_ref' => 'VS-'.uniqid(),
        ]);
    }

    private function pdf(string $name): string
    {
        return (string) file_get_contents(base_path("tests/Fixtures/pdf/label-{$name}.pdf"));
    }

    private function ids(Shipment ...$s): array
    {
        return ['ids' => array_map(fn ($x) => $x->public_id, $s)];
    }

    public function test_book_books_drafts_synchronously_and_skips_others(): void
    {
        $a = $this->ship('draft', 'draft');
        $b = $this->ship('draft', 'draft');
        $booked = $this->ship('booked', 'to_handover');
        $cancelled = $this->ship('cancelled', 'cancelled');

        $this->post('/shipments/book', $this->ids($a, $b, $booked, $cancelled))
            ->assertRedirect()
            ->assertSessionHas('status', '2 booked · 2 skipped');

        $this->assertSame('booked', $a->fresh()->state);
        $this->assertSame('booked', $b->fresh()->state);
        $this->assertNotNull($a->fresh()->tracking_number);
        $this->assertSame('booked', $booked->fresh()->state);
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_book_reports_account_error_per_row(): void
    {
        $inactive = CourierAccount::factory()->create(['courier' => 'quadx', 'status' => 'disabled']);
        $bad = $this->ship('draft', 'draft', ['courier_account_id' => $inactive->id, 'courier_ref' => 'VS-BAD']);
        $good = $this->ship('draft', 'draft');

        $this->post('/shipments/book', $this->ids($bad, $good))
            ->assertSessionHas('status', '1 booked · 1 failed');

        $this->assertSame(['VS-BAD: This courier account cannot book shipments.'], session('errors')->getBag('default')->all());
        $this->assertSame('draft', $bad->fresh()->state);
        $this->assertSame('booked', $good->fresh()->state);
    }

    public function test_book_reports_refusal_and_unknown_as_failed(): void
    {
        $refusing = CourierAccount::factory()->create(['courier' => 'spx', 'status' => 'active']);
        CourierRegistry::fake('spx', FakeCourier::make('spx')->refusesWith('Barangay not serviceable'));
        $timing = CourierAccount::factory()->create(['courier' => 'lbc', 'status' => 'active']);
        CourierRegistry::fake('lbc', FakeCourier::make('lbc')->throws(new ConnectionException('timed out')));
        $refused = $this->ship('draft', 'draft', ['courier' => 'spx', 'courier_account_id' => $refusing->id, 'courier_ref' => 'VS-R']);
        $unknown = $this->ship('draft', 'draft', ['courier' => 'lbc', 'courier_account_id' => $timing->id, 'courier_ref' => '']);
        $good = $this->ship('draft', 'draft');

        $this->post('/shipments/book', $this->ids($refused, $unknown, $good))
            ->assertRedirect()
            ->assertSessionHas('status', '1 booked · 2 failed');

        $this->assertSame([
            'VS-R: Barangay not serviceable',
            $unknown->public_id.': Booking result unknown',
        ], session('errors')->getBag('default')->all());
        $this->assertSame('failed', $refused->fresh()->state);
        $this->assertSame('unknown', $unknown->fresh()->state);
        $this->assertTrue($unknown->fresh()->needs_attention);
    }

    public function test_unknown_ids_count_as_skipped_and_not_cancellable(): void
    {
        $draft = $this->ship('draft', 'draft');

        $this->post('/shipments/book', ['ids' => [$draft->public_id, 'shp_nope', 'shp_gone']])
            ->assertSessionHas('status', '1 booked · 2 skipped');

        $this->post('/shipments/cancel', ['ids' => ['shp_nope']])
            ->assertSessionHas('status', '0 cancelled · 1 not cancellable');
    }

    public function test_cancel_mixed_states_one_failure_does_not_abort_rest(): void
    {
        $draft = $this->ship('draft', 'draft');
        $booked = $this->ship('booked', 'to_handover');
        $failed = $this->ship('failed', 'failed');
        $done = $this->ship('cancelled', 'cancelled', ['cancel_state' => 'cancelled']);

        $this->post('/shipments/cancel', $this->ids($failed, $draft, $booked, $done))
            ->assertRedirect()
            ->assertSessionHas('status', '3 cancelled · 1 not cancellable');

        $this->assertSame('cancelled', $draft->fresh()->state);
        $this->assertSame('cancelled', $booked->fresh()->state);
        $this->assertSame('cancelled', $booked->fresh()->cancel_state);
        $this->assertCount(1, $this->courier->callsTo('cancelShipment'));
    }

    public function test_cancel_refused_by_courier_is_not_cancellable(): void
    {
        $this->courier->cancels(false);
        $booked = $this->ship('booked', 'to_handover');

        $this->post('/shipments/cancel', $this->ids($booked))
            ->assertSessionHas('status', '0 cancelled · 1 not cancellable');

        $this->assertSame('booked', $booked->fresh()->state);
        $this->assertSame('refused', $booked->fresh()->cancel_state);
    }

    public function test_labels_single_pdf(): void
    {
        $s = $this->ship('booked', 'to_handover');

        $res = $this->post('/shipments/labels', $this->ids($s))->assertOk();

        $this->assertStringStartsWith('application/pdf', $res->headers->get('Content-Type'));
        $this->assertStringStartsWith('inline;', $res->headers->get('Content-Disposition'));
        $this->assertStringContainsString('waybills.pdf', $res->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF-', $res->getContent());
        $res->assertSessionMissing('status');
    }

    public function test_print_waybill_opens_in_a_new_tab(): void
    {
        $s = $this->ship('booked', 'to_handover');

        $this->get('/shipments/'.$s->public_id)->assertSee('id="sd-labels" method="POST" action="'.route('shipments.labels').'" target="_blank"', false);
        $this->get('/')->assertSee('formtarget="_blank"', false);
    }

    public function test_labels_merged_with_skipped_flash(): void
    {
        $one = $this->ship('booked', 'to_handover');
        $two = $this->ship('booked', 'to_handover');
        $draft = $this->ship('draft', 'draft');

        $res = $this->post('/shipments/labels', $this->ids($one, $draft, $two))->assertOk();

        $this->assertStringStartsWith('%PDF-', $res->getContent());
        $res->assertSessionHas('notice', '1 skipped: no waybill yet');
        $this->assertSame([], glob(storage_path('app/tmp/*')) ?: []);
    }

    public function test_skipped_notice_survives_the_poll_and_shows_once(): void
    {
        $one = $this->ship('booked', 'to_handover');
        $two = $this->ship('booked', 'to_handover');
        $draft = $this->ship('draft', 'draft');

        $this->post('/shipments/labels', $this->ids($one, $draft, $two))->assertOk();
        $this->getJson(route('fulfillment.poll'))->assertOk();
        $this->get('/')->assertOk()->assertSeeText('1 skipped: no waybill yet');
        $this->get('/')->assertOk()->assertDontSeeText('skipped: no waybill yet');
    }

    public function test_cancel_does_not_re_audit_unchanged_rows(): void
    {
        $done = $this->ship('cancelled', 'cancelled', ['cancel_state' => 'cancelled']);
        $draft = $this->ship('draft', 'draft');

        $this->post('/shipments/cancel', $this->ids($done, $draft))
            ->assertSessionHas('status', '2 cancelled');

        $this->assertSame([$draft->public_id], AuditEntry::query()->where('action', 'shipment.cancel_requested')->pluck('subject_id')->all());
    }

    public function test_labels_none_ready_redirects_with_error(): void
    {
        $draft = $this->ship('draft', 'draft');

        $this->from('/')->post('/shipments/labels', $this->ids($draft))
            ->assertRedirect('/')
            ->assertSessionHasErrors(['ids' => 'None of these have a waybill yet.']);
    }

    public function test_ids_over_100_rejected(): void
    {
        $ids = array_map(fn ($i) => 'shp_'.$i, range(1, 101));
        foreach (['book', 'cancel', 'labels'] as $action) {
            $this->post("/shipments/{$action}", ['ids' => $ids])->assertSessionHasErrors('ids');
        }
        $this->post('/shipments/book', [])->assertSessionHasErrors('ids');
    }

    public function test_actions_are_audited_with_admin_actor(): void
    {
        $d = $this->ship('draft', 'draft');
        $c = $this->ship('draft', 'draft');

        $this->post('/shipments/book', $this->ids($d));
        $this->post('/shipments/cancel', $this->ids($c));

        $book = AuditEntry::query()->where('action', 'shipment.book_requested')->sole();
        $this->assertSame('admin', $book->actor_type);
        $this->assertSame($this->admin->id, (int) $book->actor_id);
        $this->assertSame('shipment', $book->subject_type);
        $this->assertSame($d->public_id, $book->subject_id);
        $this->assertSame('127.0.0.1', $book->ip);
        $this->assertSame($c->public_id, AuditEntry::query()->where('action', 'shipment.cancel_requested')->sole()->subject_id);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        auth()->logout();
        foreach (['book', 'cancel', 'labels'] as $action) {
            $this->post("/shipments/{$action}", ['ids' => ['x']])->assertRedirect('/login');
        }
    }

    public function test_fulfillment_page_has_bulk_bar_wired_to_the_routes(): void
    {
        $this->ship('draft', 'draft');

        $this->get('/')->assertOk()
            ->assertSee(route('shipments.labels'), false)
            ->assertSee(route('shipments.book'), false)
            ->assertSee(route('shipments.cancel'), false)
            ->assertSee('Print waybills');
    }

    public function test_a_row_that_throws_is_reported_and_the_rest_still_run(): void
    {
        $boom = $this->ship('draft', 'draft', ['courier_ref' => 'VS-BOOM']);
        $good = $this->ship('draft', 'draft');
        Shipment::updating(function (Shipment $s) use ($boom) {
            if ($s->id === $boom->id) {
                throw new \RuntimeException('database went away');
            }
        });

        $this->post('/shipments/book', $this->ids($boom, $good))
            ->assertRedirect()
            ->assertSessionHas('status', '1 booked · 1 failed');
        $this->assertSame(['VS-BOOM: Something went wrong'], session('errors')->getBag('default')->all());
        $this->assertSame('draft', $boom->fresh()->state);
        $this->assertSame('booked', $good->fresh()->state);

        $other = $this->ship('draft', 'draft');
        $this->post('/shipments/cancel', $this->ids($boom, $other))
            ->assertRedirect()
            ->assertSessionHas('status', '1 cancelled · 1 not cancellable');
        $this->assertSame(['VS-BOOM: Something went wrong'], session('errors')->getBag('default')->all());
        $this->assertSame('cancelled', $other->fresh()->state);
    }

    private function failedShipment(array $over = []): Shipment
    {
        return $this->ship('failed', 'attention', $over + [
            'courier_ref' => 'VS000042',
            'client_reference' => 'ORD-42',
            'active_reference' => null,
            'error_code' => 'courier_refused',
            'error_message' => 'Cutoff time passed.',
        ]);
    }

    public function test_retry_rebooks_a_failed_shipment_through_the_normal_booking_path(): void
    {
        $this->courier->booksAs('QX-RETRY', 'pending');
        $s = $this->failedShipment();

        $this->post(route('shipments.retry', $s->public_id))
            ->assertRedirect(route('shipments.show', $s->public_id))
            ->assertSessionHas('status', 'VS000042 booked');

        $s->refresh();
        $this->assertSame('booked', $s->state);
        $this->assertSame('QX-RETRY', $s->tracking_number);
        $this->assertSame('ORD-42', $s->active_reference, 'it holds its reference again');
        $this->assertNull($s->error_code);
        $this->assertNull($s->error_message);
        $this->assertNotNull($s->booking_started_at);
        $this->assertNotNull($s->booked_at);

        // The same stored details went to the courier, once, under the shipment's own reference.
        $calls = $this->courier->callsTo('createShipment');
        $this->assertCount(1, $calls);
        $this->assertSame('VS000042', $calls[0]['args'][0]->reference);

        // As any booking: the outbox tells the client app.
        $this->assertTrue(OutgoingEvent::query()->where('shipment_id', $s->id)->where('type', 'shipment.booked')->exists());

        $audit = AuditEntry::query()->where('action', 'shipment.retry_requested')->sole();
        $this->assertSame($s->public_id, $audit->subject_id);
        $this->assertSame((string) $this->admin->id, $audit->actor_id);
    }

    public function test_retry_that_the_courier_refuses_again_stays_failed_with_the_new_reason(): void
    {
        $this->courier->refusesWith('Barangay not serviceable');
        $s = $this->failedShipment();

        $this->post(route('shipments.retry', $s->public_id))->assertRedirect(route('shipments.show', $s->public_id));

        $this->assertSame(['VS000042: Barangay not serviceable'], session('errors')->getBag('default')->all());
        $s->refresh();
        $this->assertSame('failed', $s->state);
        $this->assertSame('Barangay not serviceable', $s->error_message);
        $this->assertNull($s->active_reference, 'a refusal frees the reference again');
        $this->assertTrue(OutgoingEvent::query()->where('shipment_id', $s->id)->where('type', 'shipment.booking_failed')->exists());
        $this->assertSame(1, AuditEntry::query()->where('action', 'shipment.retry_requested')->count());
    }

    public function test_retry_refuses_anything_but_a_failed_shipment(): void
    {
        foreach ([['booked', 'to_handover'], ['unknown', 'attention'], ['queued', 'booking'], ['draft', 'draft'], ['cancelled', 'cancelled']] as [$state, $phase]) {
            $s = $this->ship($state, $phase);
            $before = $s->fresh()->only(['state', 'tracking_number', 'active_reference', 'error_message']);

            $this->post(route('shipments.retry', $s->public_id))->assertRedirect(route('shipments.show', $s->public_id));

            $this->assertSame([($s->courier_ref).' is no longer a failed booking.'], session('errors')->getBag('default')->all(), $state);
            $this->assertSame($before, $s->fresh()->only(['state', 'tracking_number', 'active_reference', 'error_message']), $state);
        }

        $cancelledFailed = $this->failedShipment(['cancel_state' => 'cancelled']);
        $this->post(route('shipments.retry', $cancelledFailed->public_id));
        $this->assertSame('failed', $cancelledFailed->fresh()->state);

        $this->assertSame([], $this->courier->callsTo('createShipment'));
        $this->assertSame(0, AuditEntry::query()->where('action', 'shipment.retry_requested')->count());
    }

    public function test_retry_refuses_when_another_live_shipment_holds_the_reference(): void
    {
        $s = $this->failedShipment();
        $resent = $this->ship('booked', 'to_handover', ['client_reference' => 'ORD-42', 'active_reference' => 'ORD-42', 'courier_ref' => 'VS000123']);
        $this->client->forceFill(['name' => 'VentaCart'])->save();

        $this->post(route('shipments.retry', $s->public_id))
            ->assertRedirect(route('shipments.show', $s->public_id))
            ->assertSessionHas('flash_link', [
                'before' => 'VentaCart already sent this order again as ',
                'label' => 'VS000123',
                'href' => route('shipments.show', $resent->public_id),
                'after' => '.',
            ]);

        $s->refresh();
        $this->assertSame('failed', $s->state);
        $this->assertNull($s->active_reference);
        $this->assertSame('Cutoff time passed.', $s->error_message);
        $this->assertSame('ORD-42', $resent->fresh()->active_reference);
        $this->assertSame([], $this->courier->callsTo('createShipment'));
        $this->assertSame(0, AuditEntry::query()->where('action', 'shipment.retry_requested')->count());

        // The flash renders with the holder linked, escaped.
        $this->get(route('shipments.show', $s->public_id))
            ->assertSee('VentaCart already sent this order again as <a class="x-flash__link" href="'.route('shipments.show', $resent->public_id).'">VS000123</a>.', false);
    }

    public function test_retry_takes_the_reference_back_from_a_released_holder(): void
    {
        $s = $this->failedShipment();
        $old = $this->ship('cancelled', 'cancelled', ['client_reference' => 'ORD-42', 'active_reference' => 'ORD-42']);

        $this->post(route('shipments.retry', $s->public_id))->assertSessionHas('status', 'VS000042 booked');

        $this->assertSame('ORD-42', $s->fresh()->active_reference);
        $this->assertNull($old->fresh()->active_reference);
    }

    public function test_retry_needs_a_signed_in_admin_and_a_post(): void
    {
        $s = $this->failedShipment();
        $this->get('/shipments/'.$s->public_id.'/retry')->assertStatus(405);
        auth()->logout();
        $this->post(route('shipments.retry', $s->public_id))->assertRedirect(route('login'));
        $this->assertSame('failed', $s->fresh()->state);
    }

    /** The page's resolve and `ventaship:shipments-resolve` leave twin shipments identical. */
    public function test_resolve_booked_matches_the_command(): void
    {
        $page = $this->ship('unknown', 'attention', ['needs_attention' => true, 'attention_reason' => BookingOutcomes::UNKNOWN_REASON, 'booking_started_at' => now()->subHour()]);
        $cli = $this->ship('unknown', 'attention', ['needs_attention' => true, 'attention_reason' => BookingOutcomes::UNKNOWN_REASON, 'booking_started_at' => now()->subHour()]);

        $this->post(route('shipments.resolve', $page->public_id), ['outcome' => 'booked', 'tracking' => '  QX-777_A  '])
            ->assertRedirect(route('shipments.show', $page->public_id))
            ->assertSessionHas('status', $page->courier_ref.' marked booked');
        $this->artisan('ventaship:shipments-resolve', ['shipment' => $cli->public_id, '--tracking' => 'QX-777_A'])->assertSuccessful();

        $cols = ['state', 'tracking_number', 'error_code', 'error_message', 'needs_attention', 'attention_reason', 'active_reference'];
        $this->assertSame('booked', $page->fresh()->state);
        $this->assertSame('QX-777_A', $page->fresh()->tracking_number);
        $this->assertNotNull($page->fresh()->booked_at);
        $this->assertSame(
            collect($cli->fresh()->only($cols))->except('active_reference')->all(),
            collect($page->fresh()->only($cols))->except('active_reference')->all(),
        );
        foreach ([$page, $cli] as $s) {
            $this->assertSame(['shipment.resolved'], OutgoingEvent::query()->where('shipment_id', $s->id)->pluck('type')->all());
        }

        $audit = AuditEntry::query()->where('action', 'shipment.resolved_booked')->sole();
        $this->assertSame($page->public_id, $audit->subject_id);
        $this->assertSame(['tracking_number' => 'QX-777_A'], $audit->changes);
    }

    public function test_resolve_not_booked_matches_the_command(): void
    {
        $page = $this->ship('unknown', 'attention', ['needs_attention' => true]);
        $cli = $this->ship('unknown', 'attention', ['needs_attention' => true]);

        $this->post(route('shipments.resolve', $page->public_id), ['outcome' => 'not_booked'])
            ->assertSessionHas('status', $page->courier_ref.' marked not booked');
        $this->artisan('ventaship:shipments-resolve', ['shipment' => $cli->public_id, '--not-booked' => true])->assertSuccessful();

        foreach ([$page, $cli] as $s) {
            $s->refresh();
            $this->assertSame('failed', $s->state);
            $this->assertNull($s->active_reference);
            $this->assertSame('not_booked', $s->error_code);
            $this->assertFalse($s->needs_attention);
            $this->assertSame(['shipment.resolved'], OutgoingEvent::query()->where('shipment_id', $s->id)->pluck('type')->all());
        }
        $this->assertSame(1, AuditEntry::query()->where('action', 'shipment.resolved_not_booked')->count());

        // It is a failed booking now: Retry shows.
        $this->assertStringContainsString('sd-retry', $this->get(route('shipments.show', $page->public_id))->getContent());
    }

    public function test_resolve_validates_the_tracking_number_like_the_command(): void
    {
        $s = $this->ship('unknown', 'attention');
        foreach (['', 'ab', '-QX123', 'QX 123', 'QX<123>', str_repeat('A', 65)] as $bad) {
            $this->post(route('shipments.resolve', $s->public_id), ['outcome' => 'booked', 'tracking' => $bad])
                ->assertSessionHasErrors('tracking');
            $this->artisan('ventaship:shipments-resolve', ['shipment' => $s->public_id, '--tracking' => $bad])->assertFailed();
        }
        $this->post(route('shipments.resolve', $s->public_id), ['outcome' => 'maybe'])->assertSessionHasErrors('outcome');

        $this->assertSame('unknown', $s->fresh()->state);
        $this->assertSame(0, OutgoingEvent::query()->where('shipment_id', $s->id)->count());
        $this->assertSame(0, AuditEntry::query()->where('subject_id', $s->public_id)->count());
    }

    public function test_resolve_refuses_a_shipment_that_is_not_unknown(): void
    {
        $s = $this->ship('booked', 'to_handover');
        $before = $s->fresh()->only(['state', 'tracking_number']);

        $this->post(route('shipments.resolve', $s->public_id), ['outcome' => 'not_booked'])
            ->assertRedirect(route('shipments.show', $s->public_id));

        $this->assertSame([$s->courier_ref.' is no longer waiting for a booking result.'], session('errors')->getBag('default')->all());
        $this->assertSame($before, $s->fresh()->only(['state', 'tracking_number']));
        $this->assertSame(0, AuditEntry::query()->count());
    }
}
