<?php

namespace App\Http\Controllers\Ui;

use App\Fulfillment\CourierCards;
use App\Fulfillment\StatusFilter;
use App\Fulfillment\Views;
use App\Fulfillment\ShipmentList;
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
        $view = Views::normalize($filters['view'] ?? null);

        $shipments = ShipmentList::query($filters)->paginate(50)->withQueryString();

        return view('fulfillment.index', [
            'cards' => CourierCards::all(),
            'counts' => ShipmentList::counts($filters),
            'shipments' => $shipments,
            'view' => $view,
            'statuses' => StatusFilter::options($filters),
            'status' => $filters['status'] ?? '',
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
            'statuses' => $ids === [] ? (object) [] : Shipment::query()->whereIn('public_id', $ids)->get(['public_id', 'courier', 'courier_status', 'state'])
                ->mapWithKeys(fn (Shipment $s) => [$s->public_id => StatusFilter::keyFor($s)]),
        ]);
    }

    /** @return array<string, string> */
    private function filters(Request $request): array
    {
        return array_filter(
            array_map(fn ($v) => is_string($v) ? $v : '', $request->only(['view', 'account', 'source', 'status'])),
            fn (string $v) => $v !== '',
        );
    }
}
