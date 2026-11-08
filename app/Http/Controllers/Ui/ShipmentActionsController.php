<?php

namespace App\Http\Controllers\Ui;

use App\Api\ApiError;
use App\Http\Controllers\Controller;
use App\Models\AuditEntry;
use App\Models\Shipment;
use App\Shipments\Canceller;
use App\Shipments\LabelBundle;
use App\Shipments\ShipmentCreator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;

class ShipmentActionsController extends Controller
{
    public function book(Request $request, ShipmentCreator $creator): RedirectResponse
    {
        $queued = 0;
        $skipped = 0;
        $errors = [];

        foreach ($this->shipments($request) as $s) {
            if ($s->phase !== 'draft' || $s->state !== 'draft') {
                $skipped++;

                continue;
            }
            try {
                $creator->submit($s);
            } catch (ApiError $e) {
                $errors[] = ($s->courier_ref ?: $s->public_id).': '.$e->getMessage();

                continue;
            }
            $queued++;
            $this->audit($request, 'shipment.book_requested', $s);
        }

        $status = "{$queued} queued for booking".($skipped > 0 ? " · {$skipped} skipped" : '');

        return back()->with('status', $status)->withErrors($errors);
    }

    public function cancel(Request $request, Canceller $canceller): RedirectResponse
    {
        $done = 0;
        $refused = 0;

        foreach ($this->shipments($request) as $s) {
            try {
                $row = $canceller->request($s);
            } catch (ApiError) {
                $refused++;

                continue;
            }
            if (in_array($row->cancel_state, ['requested', 'cancelled'], true)) {
                $done++;
                $this->audit($request, 'shipment.cancel_requested', $s);
            } else {
                $refused++;
            }
        }

        return back()->with('status', "{$done} cancelled or cancelling · {$refused} not cancellable");
    }

    public function labels(Request $request, LabelBundle $bundles): Response|RedirectResponse
    {
        try {
            $bundle = $bundles->build($this->shipments($request));
        } catch (ApiError $e) {
            return back()->withErrors(['ids' => $e->errorCode === 'label_not_ready'
                ? 'None of these have a waybill yet.'
                : $e->getMessage()]);
        }

        if ($bundle['skipped'] !== []) {
            session()->flash('status', count($bundle['skipped']).' skipped: no waybill yet');
        }

        return response($bundle['bytes'], 200, [
            'Content-Type' => $bundle['type'] === 'pdf' ? 'application/pdf' : 'application/zip',
            'Content-Disposition' => 'attachment; filename="labels.'.$bundle['type'].'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** @return Collection<int, Shipment> the requested shipments in request order; unknown ids are dropped */
    private function shipments(Request $request): Collection
    {
        $ids = array_values(array_unique($request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['required', 'string', 'max:64'],
        ])['ids']));

        $found = Shipment::query()->whereIn('public_id', $ids)->with('courierAccount')->get()->keyBy('public_id');

        return collect($ids)->map(fn ($id) => $found->get($id))->filter()->values();
    }

    private function audit(Request $request, string $action, Shipment $s): void
    {
        AuditEntry::query()->create([
            'actor_type' => 'admin',
            'actor_id' => (string) $request->user()->getAuthIdentifier(),
            'ip' => $request->ip(),
            'action' => $action,
            'subject_type' => 'shipment',
            'subject_id' => $s->public_id,
            'created_at' => now(),
        ]);
    }
}
