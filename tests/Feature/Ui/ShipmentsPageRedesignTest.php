<?php

namespace Tests\Feature\Ui;

use App\Couriers\CourierRegistry;
use App\Couriers\FakeCourier;
use App\Models\Admin;
use App\Models\ClientApp;
use App\Models\CourierAccount;
use App\Models\Shipment;
use App\Shipments\RecipientTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ShipmentsPageRedesignTest extends TestCase
{
    use RefreshDatabase;

    private CourierAccount $account;

    private ClientApp $client;

    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->admin = Admin::query()->create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'correct-horse-battery']);
        $this->actingAs($this->admin);
        $this->account = CourierAccount::factory()->create(['courier' => 'quadx', 'label' => 'QuadX', 'mode' => 'sandbox', 'last_check_ok' => true]);
        $this->client = ClientApp::factory()->create(['name' => 'Demo Store']);
    }

    protected function tearDown(): void
    {
        foreach (glob(storage_path('app/tmp/*')) ?: [] as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    private function ship(string $state, array $over = []): Shipment
    {
        return Shipment::factory()->create($over + [
            'client_app_id' => $this->client->id,
            'courier_account_id' => $this->account->id,
            'state' => $state,
            'phase' => null,
            'tracking_number' => in_array($state, ['booked', 'cancelled'], true) ? 'QX'.random_int(10000000, 99999999) : null,
            'courier_ref' => 'VS-'.uniqid(),
        ]);
    }

    private function pdf(string $name): string
    {
        return (string) file_get_contents(base_path("tests/Fixtures/pdf/label-{$name}.pdf"));
    }

    /** The markup of the row for one shipment. */
    private function row(string $html, Shipment $s): string
    {
        $this->assertMatchesRegularExpression('#<div[^>]*data-id="'.$s->public_id.'"#', $html);
        preg_match('#<div[^>]*data-id="'.$s->public_id.'"[^>]*>#', $html, $m);

        return $m[0];
    }

    // --- Needs action -----------------------------------------------------------------------------------------------

    public function test_needs_action_view_lists_failed_unknown_draft_and_attention_rows_with_a_count(): void
    {
        $failed = $this->ship('failed', ['recipient' => ['first_name' => 'Fay', 'last_name' => 'Ld']]);
        $unknown = $this->ship('unknown', ['recipient' => ['first_name' => 'Uma', 'last_name' => 'Kn']]);
        $draft = $this->ship('draft', ['recipient' => ['first_name' => 'Dee', 'last_name' => 'Ra']]);
        $flagged = $this->ship('booked', ['needs_attention' => true, 'recipient' => ['first_name' => 'Flo', 'last_name' => 'Ag']]);
        $this->ship('booked', ['recipient' => ['first_name' => 'Oki', 'last_name' => 'Ay']]);

        $html = $this->get('/?view=needs')->assertOk()
            ->assertSeeText(['Fay Ld', 'Uma Kn', 'Dee Ra', 'Flo Ag'])
            ->assertDontSeeText('Oki Ay')
            ->getContent();

        $this->assertMatchesRegularExpression('#All<em[^>]*>5</em>.*Needs action<em class="fl-seg__count is-bad[^"]*"[^>]*>4</em>.*Active<em[^>]*>5</em>.*Finished#s', $html);
        $this->assertStringContainsString('aria-current="page"', $html);
        foreach ([$failed, $unknown, $draft, $flagged] as $s) {
            $this->assertStringContainsString('is-need', $this->row($html, $s));
        }
    }

    public function test_needs_count_is_not_bad_coloured_at_zero_and_all_stays_default(): void
    {
        $this->ship('booked', ['recipient' => ['first_name' => 'Oki', 'last_name' => 'Ay']]);

        $html = $this->get('/')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#Needs action<em class="fl-seg__count"[^>]*>0</em>#', $html);
        $this->assertMatchesRegularExpression('#<a class="fl-seg__opt is-on"[^>]*aria-current="page"[^>]*>\s*All#', $html);
    }

    public function test_next_step_buttons(): void
    {
        $failed = $this->ship('failed', ['source' => 'manual', 'client_app_id' => null]);
        $unknown = $this->ship('unknown');
        $draft = $this->ship('draft');

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#href="'.preg_quote(route('shipments.edit', $failed->public_id), '#').'"[^>]*>\s*Retry#', $html);
        $this->assertMatchesRegularExpression('#href="'.preg_quote(route('shipments.show', $unknown->public_id), '#').'"[^>]*>\s*Check#', $html);
        $this->assertMatchesRegularExpression('#form="book-'.$draft->public_id.'"[^>]*>\s*Book#', $html);
        $this->assertMatchesRegularExpression('#data-id="'.$failed->public_id.'".*?x-badge--bad#s', $html);
        $this->assertMatchesRegularExpression('#data-id="'.$unknown->public_id.'".*?x-badge--attn#s', $html);
    }

    public function test_poll_counts_include_needs(): void
    {
        $this->ship('failed');
        $this->ship('booked');

        $this->getJson('/fulfillment/poll')->assertOk()
            ->assertJsonPath('counts', ['all' => 2, 'needs' => 1, 'active' => 2, 'finished' => 0]);
    }

    // --- Search -----------------------------------------------------------------------------------------------------

    public function test_search_by_reference_and_tracking(): void
    {
        $a = $this->ship('draft', ['client_reference' => 'ORD-77812', 'active_reference' => 'ORD-77812', 'recipient' => ['first_name' => 'Ann', 'last_name' => 'One']]);
        $b = $this->ship('booked', ['tracking_number' => 'QX10000001', 'recipient' => ['first_name' => 'Ben', 'last_name' => 'Two']]);

        $this->get('/?q=77812')->assertOk()->assertSeeText('Ann One')->assertDontSeeText('Ben Two');
        $this->get('/?q=qx1000000')->assertOk()->assertSeeText('Ben Two')->assertDontSeeText('Ann One');
        $this->get('/?q='.urlencode('  QX10000001  '))->assertOk()->assertSeeText('Ben Two');
    }

    public function test_search_like_wildcards_are_literal(): void
    {
        $this->ship('draft', ['recipient' => ['first_name' => 'Ann', 'last_name' => 'One']]);

        $this->get('/?q=%25')->assertOk()->assertDontSeeText('Ann One')->assertSeeText('No shipments');
        $this->get('/?q=_')->assertOk()->assertDontSeeText('Ann One');
    }

    public function test_search_by_recipient_name_words_accent_and_case_insensitive(): void
    {
        $this->ship('draft', ['recipient' => ['first_name' => 'María José', 'last_name' => 'Dela Cruz']]);
        $this->ship('draft', ['recipient' => ['first_name' => 'Jose', 'last_name' => 'Rizal']]);

        $this->get('/?q=maria')->assertOk()->assertSeeText('María José Dela Cruz')->assertDontSeeText('Jose Rizal');
        $this->get('/?q=JOSÉ')->assertOk()->assertSeeText(['María José Dela Cruz', 'Jose Rizal']);
        $this->get('/?q=jose+cruz')->assertOk()->assertSeeText('María José Dela Cruz')->assertDontSeeText('Jose Rizal');
        $this->get('/?q=mar')->assertOk()->assertDontSeeText('María José Dela Cruz');
    }

    public function test_search_query_is_capped_and_kept_in_the_box_and_counts(): void
    {
        $this->ship('draft', ['recipient' => ['first_name' => 'Ann', 'last_name' => 'One']]);
        $this->ship('draft', ['recipient' => ['first_name' => 'Ben', 'last_name' => 'Two']]);

        $html = $this->get('/?q=ann')->assertOk()->getContent();
        $this->assertStringContainsString('name="q"', $html);
        $this->assertStringContainsString('value="ann"', $html);
        $this->assertStringContainsString('maxlength="100"', $html);
        $this->assertStringContainsString('aria-label="Name, order ref or tracking number"', $html);
        $this->assertMatchesRegularExpression('#All<em[^>]*>1</em>#', $html);

        $this->get('/?q='.str_repeat('a', 500))->assertOk();
        $this->get('/?q[]=x')->assertOk()->assertSeeText(['Ann One', 'Ben Two']);
    }

    public function test_recipient_tokens_hold_no_plaintext_and_follow_changes(): void
    {
        $s = $this->ship('draft', ['recipient' => ['first_name' => 'Mariposa', 'last_name' => 'Quintana']]);

        $raw = (string) DB::table('shipments')->where('id', $s->id)->value('recipient_tokens');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}( [0-9a-f]{16})+$/', $raw);
        $this->assertStringNotContainsStringIgnoringCase('mariposa', $raw);
        $this->assertStringNotContainsStringIgnoringCase('quintana', $raw);
        $this->assertSame(RecipientTokens::forWords('Mariposa Quintana'), explode(' ', $raw));

        $s->forceFill(['recipient' => ['first_name' => 'Lena', 'last_name' => 'Uy']])->save();
        $this->get('/?q=mariposa')->assertOk()->assertDontSeeText('Lena Uy');
        $this->get('/?q=lena')->assertOk()->assertSeeText('Lena Uy');

        $s->forceFill(['recipient' => null])->save();
        $this->assertNull(DB::table('shipments')->where('id', $s->id)->value('recipient_tokens'));
    }

    public function test_tokens_use_a_key_derived_from_the_vault_key_not_the_key_itself(): void
    {
        $raw = base64_decode((string) config('ventaship.vault_key'), true);
        $plain = substr(hash_hmac('sha256', 'ann', $raw), 0, 16);

        $this->assertNotSame($plain, RecipientTokens::forWords('ann')[0]);
        $this->assertSame(16, strlen(RecipientTokens::forWords('ann')[0]));
    }

    public function test_backfill_migration_fills_tokens_for_existing_rows(): void
    {
        $s = $this->ship('draft', ['recipient' => ['first_name' => 'Backfill', 'last_name' => 'Me']]);
        DB::table('shipments')->update(['recipient_tokens' => null]);

        $migration = require database_path('migrations/2026_11_09_000900_add_recipient_tokens_to_shipments.php');
        $migration->backfill();

        $this->assertSame(
            implode(' ', RecipientTokens::forWords('Backfill Me')),
            DB::table('shipments')->where('id', $s->id)->value('recipient_tokens'),
        );
    }

    // --- Table, bulk flags ------------------------------------------------------------------------------------------

    public function test_header_row_and_table_semantics(): void
    {
        $this->ship('draft');

        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('role="table"', $html);
        foreach (['Recipient', 'Courier', 'Amount', 'Status'] as $h) {
            $this->assertMatchesRegularExpression('#role="columnheader"[^>]*>\s*'.$h.'\s*<#', $html);
        }
        $this->assertStringContainsString('role="row"', $html);
        $this->assertStringContainsString('role="cell"', $html);
    }

    public function test_row_shows_meta_line_and_cod_under_amount_and_no_prepaid_badge(): void
    {
        $this->ship('booked', [
            'client_reference' => '10001234', 'active_reference' => '10001234', 'items_count' => 2,
            'payment_method' => 'cod', 'payment_state' => 'cod_unpaid', 'cod_amount' => '980.00', 'total' => '980.00',
        ]);
        $this->ship('booked', ['payment_method' => 'prepaid', 'payment_state' => null, 'total' => '500.00']);

        $html = $this->get('/')->assertOk()
            ->assertSeeText(['10001234', 'Demo Store', '2 items', '₱980.00', 'COD', '₱500.00'])
            ->assertDontSeeText('Prepaid')
            ->getContent();
        $this->assertStringNotContainsString('COD · Unpaid', $html);
    }

    public function test_bulk_flags_per_row(): void
    {
        $booked = $this->ship('booked');
        $draft = $this->ship('draft');
        $failed = $this->ship('failed');
        $cancelled = $this->ship('cancelled', ['cancel_state' => 'cancelled']);
        $bookedNoTracking = $this->ship('booked', ['tracking_number' => null]);

        $html = $this->get('/')->assertOk()->getContent();

        $flags = fn (Shipment $s) => [
            str_contains($this->row($html, $s), 'data-printable="1"'),
            str_contains($this->row($html, $s), 'data-bookable="1"'),
            str_contains($this->row($html, $s), 'data-cancellable="1"'),
        ];
        $this->assertSame([true, false, true], $flags($booked));
        $this->assertSame([false, true, true], $flags($draft));
        $this->assertSame([false, false, false], $flags($failed));
        $this->assertSame([false, false, false], $flags($cancelled));
        $this->assertSame([false, false, true], $flags($bookedNoTracking));
    }

    public function test_bulk_bar_has_counted_buttons_deselect_and_a_live_region(): void
    {
        $this->ship('booked');

        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('aria-live="polite"', $html);
        $this->assertStringContainsString('Deselect', $html);
        foreach (["'Print waybills'", "'Book'", "'Cancel'"] as $label) {
            $this->assertStringContainsString($label, $html);
        }
        $this->assertStringContainsString('formtarget="_blank"', $html);
    }

    // --- Labels JSON ------------------------------------------------------------------------------------------------

    public function test_labels_json_success_gives_a_signed_url_serving_the_pdf(): void
    {
        CourierRegistry::fake('quadx', FakeCourier::make('quadx')->label($this->pdf('a')));
        $one = $this->ship('booked');
        $two = $this->ship('booked');

        $res = $this->postJson('/shipments/labels', ['ids' => [$one->public_id, $two->public_id]])->assertOk()
            ->assertJsonPath('ok', [$one->public_id, $two->public_id])
            ->assertJsonPath('failed', []);

        $url = (string) $res->json('url');
        $this->assertStringContainsString('signature=', $url);

        $pdf = $this->get($url)->assertOk();
        $this->assertStringStartsWith('application/pdf', $pdf->headers->get('Content-Type'));
        $this->assertStringStartsWith('inline;', $pdf->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
    }

    public function test_labels_json_partial_lists_the_failures_with_reasons(): void
    {
        $fake = FakeCourier::make('quadx')->label($this->pdf('a'));
        CourierRegistry::fake('quadx', $fake);
        $good = $this->ship('booked', ['recipient' => ['first_name' => 'Athena', 'last_name' => 'Bernhard']]);
        $draft = $this->ship('draft', ['recipient' => ['first_name' => 'Dora', 'last_name' => 'Ft']]);

        $res = $this->postJson('/shipments/labels', ['ids' => [$good->public_id, $draft->public_id, 'shp_nope']])->assertOk()
            ->assertJsonPath('ok', [$good->public_id])
            ->assertJsonPath('failed.0.id', $draft->public_id)
            ->assertJsonPath('failed.0.name', 'Dora Ft')
            ->assertJsonPath('failed.0.tracking', null)
            ->assertJsonPath('failed.0.reason', 'No waybill yet.')
            ->assertJsonPath('failed.1.id', 'shp_nope')
            ->assertJsonPath('failed.1.reason', 'Not found.');

        $this->assertNotNull($res->json('url'));
    }

    public function test_labels_json_courier_failure_reason(): void
    {
        CourierRegistry::fake('quadx', FakeCourier::make('quadx')->labelAnswer(['success' => false, 'error' => 'nope']));
        $s = $this->ship('booked', ['tracking_number' => 'QX10000002', 'recipient' => ['first_name' => 'Everardo', 'last_name' => 'Beahan']]);

        $this->postJson('/shipments/labels', ['ids' => [$s->public_id]])->assertOk()
            ->assertJsonPath('ok', [])
            ->assertJsonPath('url', null)
            ->assertJsonPath('failed.0.name', 'Everardo Beahan')
            ->assertJsonPath('failed.0.tracking', 'QX10000002')
            ->assertJsonPath('failed.0.reason', "QuadX didn't return the waybill. Try again in a minute.");
    }

    public function test_labels_json_none_printable(): void
    {
        $draft = $this->ship('draft');

        $this->postJson('/shipments/labels', ['ids' => [$draft->public_id]])->assertOk()
            ->assertJsonPath('ok', [])
            ->assertJsonPath('url', null)
            ->assertJsonCount(1, 'failed');
    }

    public function test_labels_json_validates_ids(): void
    {
        $this->postJson('/shipments/labels', [])->assertStatus(422);
    }

    public function test_signed_bundle_url_requires_auth_a_valid_signature_and_the_same_admin(): void
    {
        CourierRegistry::fake('quadx', FakeCourier::make('quadx')->label($this->pdf('a')));
        $s = $this->ship('booked');
        $url = (string) $this->postJson('/shipments/labels', ['ids' => [$s->public_id]])->json('url');

        $this->get($url.'x')->assertForbidden();
        $this->get(preg_replace('/signature=[^&]+/', 'signature=nope', $url))->assertForbidden();

        $forOther = \Illuminate\Support\Facades\URL::temporarySignedRoute('shipments.labels.bundle', now()->addMinutes(5), [
            'ids' => $s->public_id, 'u' => (string) ($this->admin->id + 1),
        ], absolute: false);
        $this->get($forOther)->assertForbidden();
        $this->get($url)->assertOk();

        auth()->logout();
        $this->get($url)->assertRedirect('/login');
    }

    public function test_signed_bundle_url_expires(): void
    {
        CourierRegistry::fake('quadx', FakeCourier::make('quadx')->label($this->pdf('a')));
        $s = $this->ship('booked');
        $url = (string) $this->postJson('/shipments/labels', ['ids' => [$s->public_id]])->json('url');

        $this->travel(6)->minutes();
        $this->get($url)->assertForbidden();
    }

    public function test_page_wires_the_json_print(): void
    {
        $js = (string) file_get_contents(resource_path('js/bulkSelect.js'));
        $this->assertStringContainsString("bulkSelect(config)", (string) file_get_contents(resource_path('js/app.js')));

        $this->assertStringContainsString("window.open('', '_blank')", $js);
        $this->assertStringContainsString("Accept: 'application/json'", $js);
        $this->assertStringContainsString("couldn't be printed", $js);
    }

    // --- Empty state, cards, filters --------------------------------------------------------------------------------

    public function test_empty_filter_result_offers_clear_filters(): void
    {
        $this->ship('booked');

        $this->get('/?view=needs')->assertOk()
            ->assertSeeText(['No shipments', 'Clear filters'])
            ->assertSee('href="'.route('fulfillment').'"', false);
        $this->get('/?q=zzz')->assertOk()->assertSeeText('Clear filters');
    }

    public function test_empty_without_filters_has_no_clear_filters(): void
    {
        $this->get('/')->assertOk()->assertSeeText('No shipments')->assertDontSeeText('Clear filters');
    }

    public function test_courier_option_does_not_repeat_the_name(): void
    {
        CourierAccount::factory()->create(['courier' => 'spx', 'label' => 'Main shop']);

        $this->get('/')->assertOk()
            ->assertDontSeeText('QuadX · QuadX')
            ->assertSeeText('SPX · Main shop');
    }

    public function test_csv_import_source_option_is_gone(): void
    {
        $this->get('/')->assertOk()->assertDontSeeText('CSV import')->assertDontSee('value="csv"', false);
    }

    public function test_cod_unpaid_at_zero_is_not_shown(): void
    {
        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringNotContainsString('COD unpaid', $html);

        $this->ship('booked', ['payment_method' => 'cod', 'payment_state' => 'cod_unpaid', 'cod_amount' => '250.00']);
        $this->get('/')->assertOk()->assertSeeText(['COD unpaid', '₱250.00']);
    }

    public function test_cards_show_mode_and_check_and_phone_chips(): void
    {
        $this->ship('booked');

        $html = $this->get('/')->assertOk()->assertSeeText(['Sandbox', 'Connected', 'No account', 'Connect LBC'])->getContent();
        $this->assertStringContainsString('fl-chips', $html);
        $this->assertMatchesRegularExpression('#fl-chip[^"]*"[^>]*>.*?<strong[^>]*>1</strong>\s*active#s', $html);
    }
}
