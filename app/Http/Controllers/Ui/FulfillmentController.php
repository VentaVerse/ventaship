<?php

namespace App\Http\Controllers\Ui;

use App\Fulfillment\CourierCards;
use App\Fulfillment\DisplayPhase;
use App\Fulfillment\ShipmentList;
use App\Fulfillment\Tabs;
use App\Http\Controllers\Controller;
use App\Models\ClientApp;
use App\Models\CourierAccount;
use App\Models\Shipment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FulfillmentController extends Controller
{
    public function index(Request $request)
    {
        $filters = $this->filters($request);
        $tab = array_key_exists($filters['tab'] ?? '', Tabs::PHASES) ? $filters['tab'] : 'all';
        $chip = $tab === 'to_ship' && isset(Tabs::CHIPS[$filters['chip'] ?? '']) ? $filters['chip'] : null;

        $shipments = ShipmentList::query($filters)->paginate(50)->withQueryString();

        return view('fulfillment.index', [
            'cards' => CourierCards::all(),
            'counts' => ShipmentList::counts($filters),
            'shipments' => $shipments,
            'tab' => $tab,
            'chip' => $chip,
            'filters' => $filters,
            'account' => $filters['account'] ?? '',
            'source' => $filters['source'] ?? '',
            'accounts' => CourierAccount::query()->orderBy('courier')->orderBy('label')->get(['public_id', 'courier', 'label']),
            'apps' => ClientApp::query()->orderBy('name')->get(['public_id', 'name']),
        ]);
    }

    public function poll(Request $request): JsonResponse
    {
        $ids = array_slice(array_values(array_unique(array_filter(
            array_map('trim', explode(',', (string) $request->query('ids', ''))),
            fn (string $id) => $id !== '',
        ))), 0, 100);

        return response()->json([
            'counts' => ShipmentList::counts($this->filters($request)),
            'phases' => $ids === [] ? (object) [] : Shipment::query()->whereIn('public_id', $ids)->get(['public_id', 'phase', 'state'])
                ->mapWithKeys(fn (Shipment $s) => [$s->public_id => DisplayPhase::of($s)]),
        ]);
    }

    /** @return array<string, string> */
    private function filters(Request $request): array
    {
        return array_filter(
            array_map(fn ($v) => is_string($v) ? $v : '', $request->only(['tab', 'chip', 'account', 'source'])),
            fn (string $v) => $v !== '',
        );
    }
}
