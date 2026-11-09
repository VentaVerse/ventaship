<?php

namespace Tests\Feature\Fulfillment;

use App\Fulfillment\ShipmentList;
use App\Fulfillment\StatusFilter;
use App\Http\Resources\ShipmentResource;
use App\Models\ClientApp;
use App\Models\CourierAccount;
use App\Models\Shipment;
use App\Shipments\PhaseResolver;
use App\Shipments\TrackingLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShipmentListTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string,int> */
    private array $ids = [];

    private CourierAccount $quadx;

    private CourierAccount $spx;

    private ClientApp $clientApp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->quadx = CourierAccount::factory()->create(['courier' => 'quadx']);
        $this->spx = CourierAccount::factory()->create(['courier' => 'spx']);
        $this->clientApp = ClientApp::factory()->create(['name' => 'VentaCart']);

        foreach (PhaseResolver::SPINE as $phase) {
            $this->ids[$phase] = $this->make($phase, $this->quadx)->id;
        }
        // unpaid in two phases; one prepaid
        $this->ids['unpaid_transit'] = $this->make('in_transit', $this->spx, ['payment_state' => 'cod_unpaid', 'cod_amount' => 100])->id;
        $this->ids['unpaid_draft'] = $this->make('draft', $this->quadx, ['payment_state' => 'cod_unpaid', 'cod_amount' => 50])->id;
        $this->ids['manual'] = $this->make('draft', $this->spx, ['client_app_id' => null, 'source' => 'manual'])->id;
        $this->ids['csv'] = $this->make('to_handover', $this->spx, ['client_app_id' => null, 'source' => 'csv'])->id;
        $this->ids['purged'] = $this->make('delivered', $this->quadx, ['recipient' => null, 'pii_purged_at' => now()])->id;
    }

    private function make(?string $phase, CourierAccount $account, array $over = []): Shipment
    {
        return Shipment::factory()->create(array_merge([
            'client_app_id' => $this->clientApp->id,
            'courier_account_id' => $account->id,
            'courier' => $account->courier,
            'phase' => $phase,
            'payment_state' => 'prepaid',
        ], $over));
    }

    /** @return list<int> */
    private function ids(array $filters): array
    {
        return ShipmentList::query($filters)->pluck('id')->sort()->values()->all();
    }

    private function want(array $keys): array
    {
        return collect($keys)->map(fn ($k) => $this->ids[$k])->sort()->values()->all();
    }

    public function test_views_split_active_and_finished(): void
    {
        $done = $this->make('delivered', $this->quadx, ['courier_status' => 'delivered'])->id;
        $cancelled = $this->make('cancelled', $this->quadx, ['state' => 'cancelled'])->id;
        $returned = $this->make('returning', $this->spx, ['terminal_at' => now()])->id;
        $draft = $this->make(null, $this->quadx, ['state' => 'draft'])->id;
        $failed = $this->make(null, $this->quadx, ['state' => 'failed'])->id;
        $transit = $this->make('in_transit', $this->spx, ['courier_status' => 'out_for_delivery'])->id;
        $own = [$done, $cancelled, $returned, $draft, $failed, $transit];
        $mine = fn (array $ids) => array_values(array_intersect($ids, $own));

        $this->assertSame([$done, $cancelled, $returned], $mine($this->ids(['view' => 'finished'])));
        $this->assertSame([$draft, $failed, $transit], $mine($this->ids(['view' => 'active'])));
        $this->assertSame($own, $mine($this->ids(['view' => 'all'])));
        $this->assertCount(Shipment::count(), $this->ids(['view' => 'nope']));
    }

    public function test_status_options_are_built_from_data_with_counts_and_labels(): void
    {
        Shipment::query()->delete();
        $this->make('in_transit', $this->quadx, ['courier_status' => 'out_for_delivery']);
        $this->make('in_transit', $this->quadx, ['courier_status' => 'out_for_delivery']);
        $this->make('delivered', $this->quadx, ['courier_status' => 'delivered']);
        $this->make('in_transit', $this->spx, ['courier_status' => 'SPX_IN_TRANSIT']);

        $opts = StatusFilter::options([]);

        $this->assertSame(['quadx:out_for_delivery', 'quadx:delivered', 'spx:SPX_IN_TRANSIT'], array_column($opts, 'key'));
        $this->assertSame([2, 1, 1], array_column($opts, 'count'));
        $this->assertSame(['quadx', 'quadx', 'spx'], array_column($opts, 'courier'));
        $this->assertSame(app(PhaseResolver::class)->label('quadx', 'out_for_delivery'), $opts[0]['label']);

        $this->assertSame(['quadx:delivered'], array_column(StatusFilter::options(['view' => 'finished']), 'key'));
        $this->assertSame(['spx:SPX_IN_TRANSIT'], array_column(StatusFilter::options(['account' => $this->spx->public_id]), 'key'));
        $this->assertSame(['quadx:delivered'], array_column(StatusFilter::options(['view' => 'finished', 'status' => 'spx:SPX_IN_TRANSIT']), 'key'));
    }

    public function test_ventaship_pseudo_status_for_unreported_rows(): void
    {
        Shipment::query()->delete();
        foreach (['draft', 'queued', 'booking', 'failed', 'unknown', 'booked'] as $state) {
            $this->make(null, $this->quadx, ['state' => $state, 'courier_status' => null]);
        }
        $this->make('in_transit', $this->quadx, ['courier_status' => 'out_for_delivery']);

        $opts = collect(StatusFilter::options([]))->keyBy('key');

        $this->assertSame('quadx:out_for_delivery', $opts->keys()->first());
        $this->assertSame('ventaship:booking', $opts->keys()->get(1));
        $this->assertEquals(
            ['ventaship:booking' => 'Booking', 'ventaship:draft' => 'Not booked', 'ventaship:failed' => 'Booking failed',
                'ventaship:unknown' => 'Booking result unknown', 'ventaship:booked' => 'Booked'],
            $opts->except('quadx:out_for_delivery')->map->label->all(),
        );
        $this->assertSame(2, $opts['ventaship:booking']['count']);
        $this->assertNull($opts['ventaship:draft']['courier']);
        $this->assertCount(2, $this->ids(['status' => 'ventaship:booking']));
        $this->assertCount(1, $this->ids(['status' => 'ventaship:failed']));
    }

    public function test_status_filter_narrows_list_and_unknown_value_is_ignored(): void
    {
        Shipment::query()->delete();
        $a = $this->make('in_transit', $this->quadx, ['courier_status' => 'out_for_delivery']);
        $this->make('delivered', $this->quadx, ['courier_status' => 'delivered']);

        $this->assertSame([$a->id], $this->ids(['status' => 'quadx:out_for_delivery']));
        $this->assertSame([$a->id], $this->ids(['status' => 'quadx:out_for_delivery', 'view' => 'active']));
        $this->assertCount(2, $this->ids(['status' => 'quadx:nonsense']));
        $this->assertCount(2, $this->ids(['status' => 'garbage']));
        $this->assertCount(2, $this->ids(['status' => 'ventaship:nope']));
    }

    public function test_counts_ignore_view_but_respect_status(): void
    {
        Shipment::query()->delete();
        $this->make('in_transit', $this->quadx, ['courier_status' => 'out_for_delivery']);
        $this->make('delivered', $this->quadx, ['courier_status' => 'delivered']);
        $this->make('delivered', $this->spx, ['courier_status' => 'delivered']);

        $this->assertSame(['all' => 3, 'active' => 1, 'finished' => 2], ShipmentList::counts(['view' => 'active']));
        $this->assertSame(['all' => 1, 'active' => 0, 'finished' => 1], ShipmentList::counts(['status' => 'quadx:delivered']));
        $this->assertSame(1, ShipmentList::counts(['account' => $this->spx->public_id])['all']);
    }

    public function test_old_tab_query_is_ignored(): void
    {
        $this->assertCount(Shipment::count(), $this->ids(['tab' => 'delivered', 'chip' => 'to_pack']));
    }

    public function test_query_is_newest_first_and_eager_loads(): void
    {
        $list = ShipmentList::query([])->get();
        $this->assertSame($list->pluck('id')->sortDesc()->values()->all(), $list->pluck('id')->all());
        $this->assertTrue($list->first()->relationLoaded('clientApp'));
        $this->assertTrue($list->first()->relationLoaded('courierAccount'));
    }

    public function test_source_filters_in_list(): void
    {
        $this->assertSame($this->want(['manual']), $this->ids(['source' => 'manual']));
        $this->assertSame($this->want(['csv']), $this->ids(['source' => 'csv']));
    }

    public function test_unknown_filter_values_fall_back(): void
    {
        $this->assertCount(Shipment::count(), $this->ids(['account' => 'acc_missing', 'source' => 'weird', 'status' => 'x', 'view' => 'zzz']));
        $this->assertSame(Shipment::count(), ShipmentList::counts(['source' => 'weird'])['all']);
    }

    public function test_source_labels(): void
    {
        $this->assertSame('VentaCart', ShipmentList::sourceLabel(Shipment::with('clientApp')->find($this->ids['draft'])));
        $this->assertSame('Manual', ShipmentList::sourceLabel(Shipment::find($this->ids['manual'])));
        $this->assertSame('CSV import', ShipmentList::sourceLabel(Shipment::find($this->ids['csv'])));
    }

    public function test_purged_rows_are_listed(): void
    {
        $row = ShipmentList::query(['view' => 'finished'])->get()->firstWhere('id', $this->ids['purged']);
        $this->assertNotNull($row);
        $this->assertNull($row->recipient);
    }

    public function test_tracking_link_matches_api_resource(): void
    {
        $s = $this->make('in_transit', $this->quadx, ['tracking_number' => 'QX-TEST-0001']);
        $none = $this->make('draft', $this->quadx);

        $this->assertSame((new ShipmentResource($s))->toArray()['tracking_url'], TrackingLink::for($s));
        $this->assertNotNull(TrackingLink::for($s));
        $this->assertNull(TrackingLink::for($none));
    }

    public function test_official_courier_labels_back_the_live_cache(): void
    {
        \App\Couriers\CourierStatusLabel::forget();
        $this->assertSame('Ready for pickup', \App\Couriers\CourierStatusLabel::for('quadx', 'for_pickup'));
        $this->assertSame('Pickup Failed', \App\Couriers\CourierStatusLabel::for('spx', '5001'));
        $this->assertSame('For reattempt', \App\Couriers\CourierStatusLabel::for('quadx', 'for_reattempt'));
    }
}
