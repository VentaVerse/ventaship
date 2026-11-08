<?php

namespace Tests\Feature\Ui;

use App\Couriers\CourierRegistry;
use App\Couriers\FakeCourier;
use App\Models\Admin;
use App\Models\AuditEntry;
use App\Models\ClientApp;
use App\Models\CourierAccount;
use App\Models\Shipment;
use App\Shipments\Jobs\BookShipment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ShipmentActionsTest extends TestCase
{
    use RefreshDatabase;

    private Admin $admin;

    private CourierAccount $account;

    private ClientApp $client;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->admin = Admin::query()->create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'correct-horse-battery']);
        $this->actingAs($this->admin);
        $this->account = CourierAccount::factory()->create(['courier' => 'quadx', 'status' => 'active']);
        $this->client = ClientApp::factory()->create();
        CourierRegistry::fake('quadx', FakeCourier::make('quadx')->label($this->pdf('a')));
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

    public function test_book_queues_drafts_and_skips_others(): void
    {
        Queue::fake();
        $a = $this->ship('draft', 'draft');
        $b = $this->ship('draft', 'draft');
        $booked = $this->ship('booked', 'to_handover');
        $cancelled = $this->ship('cancelled', 'cancelled');

        $this->post('/shipments/book', $this->ids($a, $b, $booked, $cancelled))
            ->assertRedirect()
            ->assertSessionHas('status', '2 queued for booking · 2 skipped');

        Queue::assertPushed(BookShipment::class, 2);
        $this->assertSame('queued', $a->fresh()->state);
        $this->assertSame('booked', $booked->fresh()->state);
    }

    public function test_book_reports_account_error_per_row(): void
    {
        Queue::fake();
        $inactive = CourierAccount::factory()->create(['courier' => 'quadx', 'status' => 'disabled']);
        $bad = $this->ship('draft', 'draft', ['courier_account_id' => $inactive->id, 'courier_ref' => 'VS-BAD']);
        $good = $this->ship('draft', 'draft');

        $res = $this->post('/shipments/book', $this->ids($bad, $good))
            ->assertSessionHas('status', '1 queued for booking');

        Queue::assertPushed(BookShipment::class, 1);
        $this->assertSame(['VS-BAD: This courier account cannot book shipments.'], session('errors')->getBag('default')->all());
        $this->assertSame('draft', $bad->fresh()->state);
    }

    public function test_unknown_ids_count_as_skipped_and_not_cancellable(): void
    {
        Queue::fake();
        $draft = $this->ship('draft', 'draft');

        $this->post('/shipments/book', ['ids' => [$draft->public_id, 'shp_nope', 'shp_gone']])
            ->assertSessionHas('status', '1 queued for booking · 2 skipped');

        $this->post('/shipments/cancel', ['ids' => ['shp_nope']])
            ->assertSessionHas('status', '0 cancelled or cancelling · 1 not cancellable');
    }

    public function test_cancel_mixed_states_one_failure_does_not_abort_rest(): void
    {
        Queue::fake();
        $draft = $this->ship('draft', 'draft');
        $booked = $this->ship('booked', 'to_handover');
        $failed = $this->ship('failed', 'failed');
        $done = $this->ship('cancelled', 'cancelled', ['cancel_state' => 'cancelled']);

        $this->post('/shipments/cancel', $this->ids($failed, $draft, $booked, $done))
            ->assertRedirect()
            ->assertSessionHas('status', '3 cancelled or cancelling · 1 not cancellable');

        $this->assertSame('cancelled', $draft->fresh()->state);
        $this->assertSame('requested', $booked->fresh()->cancel_state);
    }

    public function test_labels_single_pdf(): void
    {
        $s = $this->ship('booked', 'to_handover');

        $res = $this->post('/shipments/labels', $this->ids($s))->assertOk();

        $this->assertStringStartsWith('application/pdf', $res->headers->get('Content-Type'));
        $this->assertStringContainsString('labels.pdf', $res->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF-', $res->getContent());
        $res->assertSessionMissing('status');
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
        Queue::fake();
        $done = $this->ship('cancelled', 'cancelled', ['cancel_state' => 'cancelled']);
        $draft = $this->ship('draft', 'draft');

        $this->post('/shipments/cancel', $this->ids($done, $draft))
            ->assertSessionHas('status', '2 cancelled or cancelling · 0 not cancellable');

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
        Queue::fake();
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
}
