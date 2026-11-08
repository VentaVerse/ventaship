<?php

namespace App\Http\Controllers\Api\V1;

use App\Api\ClientContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PrintLabelsRequest;
use App\Models\Shipment;
use App\Shipments\LabelBundle;
use App\Shipments\LabelStore;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

class LabelsController extends Controller
{
    public function __construct(private readonly LabelStore $labels) {}

    /** GET /shipments/{id}/label: the waybill PDF, fetched from the courier the first time. */
    public function show(string $shipment): Response
    {
        $s = Shipment::query()
            ->where('client_app_id', ClientContext::app()->getKey())
            ->where('public_id', $shipment)
            ->with('courierAccount')
            ->firstOrFail();

        $label = $this->labels->get($s);

        return $this->download(Storage::disk('local')->get($label->path), 'application/pdf', $s->public_id.'.pdf');
    }

    /**
     * POST /labels: one PDF (or a ZIP when merging is not possible) of the caller's printable shipments. Ids that are
     * unknown, foreign, not booked or unfetchable are skipped, never errors: `Ventaship-Skipped` lists them.
     */
    public function store(PrintLabelsRequest $request, LabelBundle $bundles): Response
    {
        $requested = $request->ids();
        $found = Shipment::query()
            ->where('client_app_id', ClientContext::app()->getKey())
            ->whereIn('public_id', $requested)
            ->with('courierAccount')
            ->get()
            ->keyBy('public_id');

        $bundle = $bundles->build(collect($requested)->map(fn ($id) => $found->get($id))->filter()->values());

        $printed = array_diff($found->keys()->all(), $bundle['skipped']);
        $headers = [];
        if (($skipped = array_values(array_diff($requested, $printed))) !== []) {
            $headers['Ventaship-Skipped'] = implode(',', $skipped);
        }

        return $this->download(
            $bundle['bytes'],
            $bundle['type'] === 'pdf' ? 'application/pdf' : 'application/zip',
            'labels.'.$bundle['type'],
            $headers,
        );
    }

    private function download(string $bytes, string $type, string $name, array $headers = []): Response
    {
        return response($bytes, 200, $headers + [
            'Content-Type' => $type,
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
