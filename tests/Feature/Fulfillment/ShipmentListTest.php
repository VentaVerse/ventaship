<?php

namespace Tests\Feature\Fulfillment;

use App\Fulfillment\ShipmentList;
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

    private function make(string $phase, CourierAccount $account, array $over = []): Shipment
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

    public function test_tab_phase_mapping(): void
    {
        $this->assertSame(Shipment::count(), count($this->ids(['tab' => 'all'])));
        $this->assertSame($this->want(['draft', 'booking', 'attention', 'to_handover', 'unpaid_draft', 'manual', 'csv']), $this->ids(['tab' => 'to_ship']));
        $this->assertSame($this->want(['draft', 'attention', 'unpaid_draft', 'manual']), $this->ids(['tab' => 'to_ship', 'chip' => 'to_pack']));
        $this->assertSame($this->want(['booking', 'to_handover', 'csv']), $this->ids(['tab' => 'to_ship', 'chip' => 'to_handover']));
        $this->assertSame($this->want(['in_transit', 'unpaid_transit']), $this->ids(['tab' => 'shipping']));
        $this->assertSame($this->want(['failed']), $this->ids(['tab' => 'failed']));
        $this->assertSame($this->want(['lost_damaged']), $this->ids(['tab' => 'lost_damaged']));
        $this->assertSame($this->want(['delivered', 'purged']), $this->ids(['tab' => 'delivered']));
        $this->assertSame($this->want(['returning']), $this->ids(['tab' => 'returning']));
        $this->assertSame($this->want(['cancelled']), $this->ids(['tab' => 'cancelled']));
    }

    public function test_unpaid_spans_phases(): void
    {
        $this->assertSame($this->want(['unpaid_transit', 'unpaid_draft']), $this->ids(['tab' => 'unpaid']));
    }

    public function test_query_is_newest_first_and_eager_loads(): void
    {
        $list = ShipmentList::query([])->get();
        $this->assertSame($list->pluck('id')->sortDesc()->values()->all(), $list->pluck('id')->all());
        $this->assertTrue($list->first()->relationLoaded('clientApp'));
        $this->assertTrue($list->first()->relationLoaded('courierAccount'));
    }

    public function test_counts_ignore_tab_but_respect_courier_and_source(): void
    {
        $all = ShipmentList::counts(['tab' => 'failed', 'chip' => 'to_pack']);
        $this->assertSame(Shipment::count(), $all['all']);
        $this->assertSame(2, $all['unpaid']);
        $this->assertSame(7, $all['to_ship']);
        $this->assertSame(4, $all['to_pack']);
        $this->assertSame(3, $all['to_handover']);
        $this->assertSame(2, $all['shipping']);
        $this->assertSame(1, $all['failed']);
        $this->assertSame(1, $all['cancelled']);

        $spx = ShipmentList::counts(['account' => $this->spx->public_id]);
        $this->assertSame(3, $spx['all']);
        $this->assertSame(1, $spx['unpaid']);
        $this->assertSame(2, $spx['to_ship']);
        $this->assertSame(0, $spx['failed']);

        $csv = ShipmentList::counts(['source' => 'csv']);
        $this->assertSame(1, $csv['all']);
        $this->assertSame(1, $csv['to_handover']);

        $api = ShipmentList::counts(['source' => 'api:'.$this->clientApp->public_id]);
        $this->assertSame(Shipment::count() - 2, $api['all']);
    }

    public function test_source_filters_in_list(): void
    {
        $this->assertSame($this->want(['manual']), $this->ids(['source' => 'manual']));
        $this->assertSame($this->want(['csv']), $this->ids(['source' => 'csv']));
    }

    public function test_unknown_filter_values_fall_back(): void
    {
        $total = Shipment::count();
        $this->assertCount($total, $this->ids(['tab' => 'nope', 'chip' => 'zzz', 'account' => 'acc_missing', 'source' => 'weird']));
        $this->assertCount($total, $this->ids(['tab' => 'nope']));
        $this->assertCount($total, $this->ids(['tab' => 'all', 'chip' => 'to_pack']));
        $this->assertCount(count($this->want(['draft', 'booking', 'attention', 'to_handover', 'unpaid_draft', 'manual', 'csv'])), $this->ids(['tab' => 'to_ship', 'chip' => 'bogus']));
        $this->assertSame(Shipment::count(), ShipmentList::counts(['tab' => 'x', 'source' => 'weird'])['all']);
    }

    public function test_source_labels(): void
    {
        $this->assertSame('VentaCart', ShipmentList::sourceLabel(Shipment::with('clientApp')->find($this->ids['draft'])));
        $this->assertSame('Manual', ShipmentList::sourceLabel(Shipment::find($this->ids['manual'])));
        $this->assertSame('CSV import', ShipmentList::sourceLabel(Shipment::find($this->ids['csv'])));
    }

    public function test_purged_rows_are_listed(): void
    {
        $row = ShipmentList::query(['tab' => 'delivered'])->get()->firstWhere('id', $this->ids['purged']);
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
}
