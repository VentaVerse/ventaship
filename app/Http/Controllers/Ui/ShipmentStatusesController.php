<?php

namespace App\Http\Controllers\Ui;

use App\Http\Controllers\Controller;
use App\Models\ShipmentStatus;
use App\Shipments\ShipmentStatuses;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ShipmentStatusesController extends Controller
{
    public function index(ShipmentStatuses $statuses): View
    {
        return view('statuses.index', ['statuses' => $statuses->all()]);
    }

    public function create(): View
    {
        return view('statuses.form', ['status' => null]);
    }

    public function store(Request $request, ShipmentStatuses $statuses): RedirectResponse
    {
        $data = $this->validated($request, null);
        $statuses->create($data['name'], $data['enabled'], $this->actor($request));

        return redirect()->route('statuses.index')->with('status', 'Added');
    }

    public function edit(ShipmentStatus $status): View
    {
        return view('statuses.form', ['status' => $status]);
    }

    public function update(Request $request, ShipmentStatus $status, ShipmentStatuses $statuses): RedirectResponse
    {
        $data = $this->validated($request, $status);
        $statuses->update($status, $data['name'], $data['enabled'], $this->actor($request));

        return redirect()->route('statuses.index')->with('status', 'Saved');
    }

    /** The list's switch: on or off without opening the status. */
    public function toggle(Request $request, ShipmentStatus $status, ShipmentStatuses $statuses): RedirectResponse
    {
        $statuses->setEnabled($status, $request->boolean('enabled'), $this->actor($request));

        return redirect()->route('statuses.index')->with('status', $request->boolean('enabled') ? 'Enabled' : 'Disabled');
    }

    public function destroy(Request $request, ShipmentStatus $status, ShipmentStatuses $statuses): RedirectResponse
    {
        $statuses->delete($status, $this->actor($request));

        return redirect()->route('statuses.index')->with('status', 'Deleted');
    }

    /** @return array{name: string, enabled: bool} */
    private function validated(Request $request, ?ShipmentStatus $status): array
    {
        $request->merge(['name' => trim((string) $request->input('name'))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:40', Rule::unique('shipment_statuses', 'name')->ignore($status?->id)],
        ], [
            'name.required' => 'Enter a status name.',
            'name.unique' => 'A status with this name exists.',
        ]);

        return ['name' => $data['name'], 'enabled' => $request->boolean('enabled')];
    }

    /** @return array{type: string, id: mixed, ip: ?string} */
    private function actor(Request $request): array
    {
        return ['type' => 'admin', 'id' => $request->user()->getAuthIdentifier(), 'ip' => $request->ip()];
    }
}
