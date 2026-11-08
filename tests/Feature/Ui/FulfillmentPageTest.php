<?php

namespace Tests\Feature\Ui;

use App\Models\Admin;
use App\Models\ClientApp;
use App\Models\CourierAccount;
use App\Models\Shipment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class FulfillmentPageTest extends TestCase
{
    use RefreshDatabase;

    private CourierAccount $account;

    private ClientApp $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(Admin::query()->create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'correct-horse-battery']));
        $this->account = CourierAccount::factory()->create(['courier' => 'quadx', 'mode' => 'production', 'last_check_ok' => true]);
        $this->client = ClientApp::factory()->create(['name' => 'VentaCart Pro']);
    }

    private function ship(string $phase, array $over = []): Shipment
    {
        return Shipment::factory()->create(array_merge([
            'client_app_id' => $this->client->id,
            'courier_account_id' => $this->account->id,
            'phase' => $phase,
        ], $over));
    }

    public function test_page_renders_cards_views_and_rows(): void
    {
        $this->ship('to_handover', [
            'courier_ref' => 'VS-24-00182', 'state' => 'booked', 'payment_method' => 'cod', 'payment_state' => 'cod_unpaid',
            'cod_amount' => '1240.00', 'total' => '1500.00',
            'recipient' => ['first_name' => 'Maria', 'last_name' => 'Santos', 'city' => 'Pasig City'],
            'items_count' => 2,
        ]);

        $this->get('/')->assertOk()
            ->assertSeeText(['Shipments', 'All', 'Active', 'Finished', 'All statuses'])
            ->assertSeeText(['QuadX', 'Live', 'Connected', 'LBC', 'No account'])
            ->assertSeeText(['VS-24-00182', 'Maria Santos', 'Pasig City', '2 items', '₱1,240.00', 'COD · Unpaid', 'Booked', 'VentaCart Pro', 'Connect LBC']);
    }

    public function test_purged_row_renders_removed(): void
    {
        $this->ship('delivered', ['recipient' => null, 'pii_purged_at' => now()]);

        $this->get('/?view=finished')->assertOk()->assertSeeText('Removed');
    }

    public function test_pagination_keeps_filters(): void
    {
        Shipment::factory()->count(51)->create([
            'client_app_id' => null, 'source' => 'manual', 'phase' => 'draft', 'state' => 'draft', 'courier_account_id' => $this->account->id,
        ]);

        $html = $this->get('/?view=active&status=ventaship:draft&source=manual')->assertOk()->getContent();
        $this->assertStringContainsString('view=active&amp;status=ventaship%3Adraft&amp;source=manual&amp;page=2', $html);
    }

    public function test_draft_row_book_form_posts_single_id(): void
    {
        Route::post('/shipments/book', fn () => 'x')->name('shipments.book');
        app('router')->getRoutes()->refreshNameLookups();
        $draft = $this->ship('draft', ['state' => 'draft']);
        $booked = $this->ship('to_handover');

        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('id="book-'.$draft->public_id.'"', $html);
        $this->assertStringContainsString('form="book-'.$draft->public_id.'"', $html);
        $this->assertMatchesRegularExpression('#<form id="book-'.$draft->public_id.'" method="POST" action="[^"]*/shipments/book">\s*<input type="hidden" name="_token"[^>]*>\s*<input type="hidden" name="ids\[\]" value="'.$draft->public_id.'">#', $html);
        $this->assertStringNotContainsString('book-'.$booked->public_id, $html);
    }

    public function test_page_title_and_nav_say_shipments(): void
    {
        $this->get('/')->assertOk()
            ->assertSee('<title>Shipments', false)
            ->assertSeeText('Shipments')
            ->assertDontSeeText('Fulfillment');
    }

    public function test_old_tab_query_is_ignored(): void
    {
        $this->ship('delivered', ['recipient' => ['first_name' => 'Zed', 'last_name' => 'Q']]);
        $this->ship('draft', ['state' => 'draft', 'recipient' => ['first_name' => 'Amy', 'last_name' => 'W']]);

        $this->get('/?tab=failed&chip=to_pack')->assertOk()->assertSeeText(['Zed Q', 'Amy W']);
    }

    public function test_filter_bar_has_views_and_status_select_with_counts(): void
    {
        $this->ship('in_transit', ['courier' => 'quadx', 'courier_status' => 'out_for_delivery']);
        $this->ship('delivered', ['courier' => 'quadx', 'courier_status' => 'delivered']);

        $this->get('/?view=active')->assertOk()
            ->assertSeeText(['QuadX · Out for delivery (1)'])
            ->assertSee('name="status"', false)
            ->assertSee('data-status="quadx:out_for_delivery"', false)
            ->assertDontSee('data-status="quadx:delivered"', false);
    }

    public function test_account_select_and_active_status_with_zero_count(): void
    {
        $this->ship('delivered', ['courier' => 'quadx', 'courier_status' => 'delivered']);

        $this->get('/?view=active&status=quadx:delivered')->assertOk()
            ->assertSeeText('QuadX · '.$this->account->label)
            ->assertSee('value="quadx:delivered" selected', false)
            ->assertSeeText('QuadX · Delivered (0)');
    }

    public function test_status_key_is_checked_once_per_request(): void
    {
        $this->ship('in_transit', ['courier' => 'quadx', 'courier_status' => 'out_for_delivery']);

        $checks = 0;
        \Illuminate\Support\Facades\DB::listen(function ($q) use (&$checks) {
            if (str_contains($q->sql, 'exists') && str_contains($q->sql, '"courier_status" = ?')) {
                $checks++;
            }
        });

        $this->get('/?status=quadx:out_for_delivery')->assertOk()->assertSeeText('QuadX · Out for delivery (1)');
        $this->assertSame(1, $checks);

        $this->get('/?status=quadx:out_for_delivery')->assertOk();
        $this->assertSame(2, $checks);
    }

    public function test_poll_returns_status_keys_without_nulls(): void
    {
        $a = $this->ship('draft', ['state' => 'draft', 'courier_status' => null]);
        $b = $this->ship('delivered', ['courier' => 'quadx', 'courier_status' => 'delivered']);

        $this->getJson('/fulfillment/poll?ids='.$a->public_id.','.$b->public_id.',shp_nope')
            ->assertOk()
            ->assertJsonPath('statuses.'.$a->public_id, 'ventaship:draft')
            ->assertJsonPath('statuses.'.$b->public_id, 'quadx:delivered')
            ->assertJsonMissingPath('statuses.shp_nope')
            ->assertJsonPath('counts', ['all' => 2, 'active' => 1, 'finished' => 1]);
    }

    public function test_poll_is_sent_as_xhr_and_never_becomes_the_previous_url(): void
    {
        $js = (string) file_get_contents(resource_path('js/app.js'));
        $this->assertMatchesRegularExpression("#'X-Requested-With': 'XMLHttpRequest'#", $js);

        $this->get('/settings')->assertOk();
        $this->getJson('/fulfillment/poll?ids=a', ['X-Requested-With' => 'XMLHttpRequest'])->assertOk();
        $this->assertStringEndsWith('/settings', (string) session()->previousUrl());
    }

    public function test_poll_caps_ids_at_100(): void
    {
        $rows = Shipment::factory()->count(120)->create(['courier_account_id' => $this->account->id, 'phase' => 'draft']);

        $res = $this->getJson('/fulfillment/poll?ids='.$rows->pluck('public_id')->implode(','))->assertOk();
        $this->assertCount(100, $res->json('statuses'));
    }

    public function test_guest_cannot_poll(): void
    {
        auth()->logout();
        $this->getJson('/fulfillment/poll?ids=a')->assertUnauthorized();
    }
}
