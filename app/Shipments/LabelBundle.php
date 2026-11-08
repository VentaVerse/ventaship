<?php

namespace App\Shipments;

use App\Api\ApiError;
use App\Models\Shipment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Fetches the waybills of a set of shipments and merges them into one PDF (or a ZIP when merging is not possible).
 * Shipments without a printable waybill are skipped, never errors; only an empty result throws.
 */
final class LabelBundle
{
    public function __construct(private readonly LabelStore $labels, private readonly LabelMerger $merger) {}

    /**
     * @param  Collection<int, Shipment>  $shipments  in print order
     * @return array{bytes: string, type: 'pdf'|'zip', skipped: list<string>} skipped = public ids that were not printed
     *
     * @throws ApiError 502 courier_unavailable, 409 label_not_ready
     */
    public function build(Collection $shipments): array
    {
        $paths = [];
        $courierFailed = false;
        $retryable = false;
        foreach ($shipments as $s) {
            try {
                $paths[$s->public_id] = Storage::disk('local')->path($this->labels->get($s)->path);
            } catch (ApiError $e) {
                if ($e->status === 502) {
                    $courierFailed = true;
                    $retryable = $retryable || $e->retryable;
                }
            }
        }

        if ($paths === []) {
            throw $courierFailed
                ? new ApiError(502, 'courier_unavailable', 'The labels could not be fetched from the courier.', $retryable)
                : new ApiError(409, 'label_not_ready', 'None of these shipments has a waybill to print.');
        }

        $skipped = array_values(array_diff($shipments->pluck('public_id')->all(), array_keys($paths)));

        if (count($paths) === 1) {
            return ['bytes' => (string) file_get_contents(reset($paths)), 'type' => 'pdf', 'skipped' => $skipped];
        }

        $merged = $this->merger->merge($paths);
        try {
            $bytes = (string) file_get_contents($merged['path']);
        } finally {
            @unlink($merged['path']);
        }

        return ['bytes' => $bytes, 'type' => $merged['type'], 'skipped' => $skipped];
    }
}
