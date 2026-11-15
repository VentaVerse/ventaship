<?php

namespace App\Http\Controllers\Ui;

use App\Couriers\CourierAccounts;
use App\Http\Controllers\Controller;
use App\Shipments\ShipmentStatuses;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The Status mapping tab of a courier page: each courier status picks one of the owner's shipment statuses. */
class StatusMappingController extends Controller
{
    public function show(string $courier, ShipmentStatuses $statuses, CourierAccounts $accounts): View
    {
        abort_unless(in_array($courier, ShipmentStatuses::MAPPABLE, true), 404);

        return view('accounts.mapping', [
            'courier' => $courier,
            'account' => $accounts->forCourier($courier),
            'rows' => $statuses->mapping($courier),
            'options' => $statuses->options(),
        ]);
    }

    public function update(Request $request, string $courier, ShipmentStatuses $statuses): RedirectResponse
    {
        abort_unless(in_array($courier, ShipmentStatuses::MAPPABLE, true), 404);
        $chosen = array_map(fn ($v) => is_string($v) ? $v : '', (array) $request->input('statuses', []));

        $statuses->saveMapping($courier, $chosen, ['type' => 'admin', 'id' => $request->user()->getAuthIdentifier(), 'ip' => $request->ip()]);

        return redirect()->route('accounts.mapping', $courier)->with('status', 'Saved');
    }
}
