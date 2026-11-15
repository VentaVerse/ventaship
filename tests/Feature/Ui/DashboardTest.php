<?php

namespace Tests\Feature\Ui;

use App\Fulfillment\Dashboard;
use App\Models\Admin;
use App\Models\ClientApp;
use App\Models\CourierAccount;
use App\Models\OutgoingEvent;
use App\Models\Shipment;
use App\Models\ShipmentStatus;
use App\Shipments\ShipmentStatuses;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The home page: today's figures, 30 days of bookings by courier, statuses, recent shipments, couriers, needs action. */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private CourierAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-16 15:00', 'Asia/Manila'));
        $this->actingAs(Admin::query()->create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'correct-horse-battery']));
        $this->account = CourierAccount::factory()->create(['courier' => 'quadx', 'mode' => 'production', 'last_check_ok' => true]);
    }

    private function ship(array $over = []): Shipment
    {
        return Shipment::factory()->create(array_merge(['courier_account_id' => $this->account->id, 'courier' => 'quadx', 'state' => 'booked'], $over));
    }

    public function test_home_is_the_dashboard_and_shipments_moved(): void
    {
        $this->get('/')->assertOk()->assertSee('<title>Dashboard', false)
            ->assertSeeInOrder(['Booked today', 'In transit', 'Delivered today', 'Needs action', 'Shipments, last 30 days', 'By status', 'Recent shipments', 'Couriers'])
            ->assertSee(route('fulfillment'), false);
        $this->assertSame(url('/shipments'), route('fulfillment'));
        $this->get('/shipments')->assertOk()->assertSee('<title>Shipments', false);
    }

    public function test_today_figures(): void
    {
        $this->ship(['booked_at' => now(), 'phase' => 'in_transit']);
        $this->ship(['booked_at' => now()->subHours(2), 'phase' => 'to_handover']);
        $this->ship(['booked_at' => now()->subDay(), 'phase' => 'delivered', 'last_event_at' => now()->subHour(), 'terminal_at' => now()->subHour()]);
        $this->ship(['booked_at' => now()->subDays(5), 'phase' => 'delivered', 'last_event_at' => now()->subDays(3), 'terminal_at' => now()->subDays(3)]);
        $this->ship(['state' => 'failed', 'booked_at' => null]);

        $k = (new Dashboard)->kpis();
        $this->assertSame(['booked' => 2, 'booked_yesterday' => 1, 'in_transit' => 1, 'to_handover' => 1, 'delivered' => 1, 'delivered_month' => 2, 'needs' => 1], $k);
    }

    public function test_trend_stacks_couriers_in_fixed_order(): void
    {
        $spx = CourierAccount::factory()->create(['courier' => 'spx']);
        $this->ship(['booked_at' => now()]);
        $this->ship(['booked_at' => now(), 'courier' => 'spx', 'courier_account_id' => $spx->id]);
        $this->ship(['booked_at' => now()->subDays(29)]);
        $this->ship(['booked_at' => now()->subDays(30)]); // outside the window

        $t = (new Dashboard)->trend();
        $this->assertCount(30, $t['days']);
        $this->assertSame(['quadx', 'spx'], $t['couriers']);
        $this->assertSame(['quadx' => 2, 'spx' => 1], $t['totals']);
        $this->assertSame(['quadx' => 1, 'spx' => 1], $t['days'][29]['counts']);
        $this->assertSame(['quadx' => 1, 'spx' => 0], $t['days'][0]['counts']);
        $this->assertSame(4, $t['max']);

        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('db-key--1"></i>QuadX 2', $html);
        $this->assertStringContainsString('db-key--2"></i>SPX 1', $html);
        $this->assertStringNotContainsString('style="', $html);
    }

    public function test_by_status_follows_enabled_statuses_and_shows_unmapped(): void
    {
        $this->ship(['phase' => 'delivered']);
        $this->ship(['phase' => null, 'courier_status' => '9999']);
        ShipmentStatus::query()->where('key', 'lost_damaged')->update(['enabled' => false]);

        $rows = collect((new Dashboard)->byStatus())->pluck('count', 'name');
        $this->assertSame(1, $rows['Delivered']);
        $this->assertSame(1, $rows['Unmapped']);
        $this->assertFalse($rows->has('Lost or damaged'));
    }

    public function test_needs_action_lists_shipments_unmapped_statuses_and_failing_webhooks(): void
    {
        $this->get('/')->assertSee('All clear');

        $this->ship(['state' => 'failed']);
        ShipmentStatus::query()->where('key', 'delivered')->update(['enabled' => false]);
        $app = ClientApp::factory()->create(['name' => 'Seller Center', 'webhook_url' => 'https://sc.test/hook']);
        OutgoingEvent::query()->create(['client_app_id' => $app->id, 'type' => 'ping', 'payload' => [], 'status' => 'failed', 'attempts' => 3, 'last_error' => 'HTTP 500']);

        $needs = (new Dashboard)->needs(app(ShipmentStatuses::class));
        $this->assertSame(['1 shipment', 'QuadX Delivered', 'Seller Center webhook'], array_column($needs, 'what'));
        $this->assertSame(['Failed, unconfirmed or draft', 'Unmapped', 'HTTP 500'], array_column($needs, 'why'));
        $this->assertSame(route('accounts.mapping', 'quadx'), $needs[1]['href']);

        // Many unmapped at once read as one line per courier.
        ShipmentStatus::query()->where('key', 'to_handover')->update(['enabled' => false]);
        $what = array_column((new Dashboard)->needs(app(ShipmentStatuses::class)), 'what');
        $this->assertMatchesRegularExpression('/^QuadX · \d+ statuses$/', $what[1]);
    }

    public function test_recent_and_couriers(): void
    {
        $this->ship(['recipient' => ['first_name' => 'Maria', 'last_name' => 'Santos'], 'phase' => 'in_transit']);
        CourierAccount::factory()->create(['courier' => 'spx', 'status' => 'inactive']);

        $this->get('/')->assertOk()
            ->assertSeeInOrder(['Recent shipments', 'Maria Santos', 'QuadX'])
            ->assertSee('brand/couriers/quadx.png', false)
            ->assertDontSee('brand/couriers/spx.png', false);
    }

    public function test_menu_starts_with_dashboard(): void
    {
        $this->get(route('statuses.index'))->assertOk()->assertSeeInOrder(['>Dashboard<', '>Shipments<', '>Add shipment<'], false);
    }
}
