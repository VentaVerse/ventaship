<?php

namespace App\Http\Controllers\Api\V1;

use App\Api\ApiError;
use App\Api\ClientContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PrintLabelsRequest;
use App\Models\Shipment;
use App\Shipments\LabelMerger;
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
    public function store(PrintLabelsRequest $request, LabelMerger $merger): Response
    {
        $requested = $request->ids();
        $found = Shipment::query()
            ->where('client_app_id', ClientContext::app()->getKey())
            ->whereIn('public_id', $requested)
            ->with('courierAccount')
            ->get()
            ->keyBy('public_id');

        $paths = [];
        $courierDown = false;
        foreach ($requested as $id) {
            if (($s = $found->get($id)) === null) {
                continue;
            }
            try {
                $paths[$id] = Storage::disk('local')->path($this->labels->get($s)->path);
            } catch (ApiError $e) {
                $courierDown = $courierDown || $e->status === 502;
            }
        }

        if ($paths === []) {
            throw $courierDown
                ? new ApiError(502, 'courier_unavailable', 'The labels could not be fetched from the courier.', true)
                : new ApiError(409, 'label_not_ready', 'None of these shipments has a waybill to print.');
        }

        $headers = [];
        if (($skipped = array_values(array_diff($requested, array_keys($paths)))) !== []) {
            $headers['Ventaship-Skipped'] = implode(',', $skipped);
        }

        if (count($paths) === 1) {
            return $this->download((string) file_get_contents(reset($paths)), 'application/pdf', 'labels.pdf', $headers);
        }

        $merged = $merger->merge($paths);
        try {
            $bytes = (string) file_get_contents($merged['path']);
        } finally {
            @unlink($merged['path']);
        }

        return $this->download(
            $bytes,
            $merged['type'] === 'pdf' ? 'application/pdf' : 'application/zip',
            'labels.'.$merged['type'],
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
