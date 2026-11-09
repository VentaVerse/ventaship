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
        ['paths' => $paths, 'failed' => $failed] = $this->collect($shipments);

        if ($paths === []) {
            $courierFailed = false;
            $retryable = false;
            foreach ($failed as $e) {
                if ($e->status === 502) {
                    $courierFailed = true;
                    $retryable = $retryable || $e->retryable;
                }
            }

            throw $courierFailed
                ? new ApiError(502, 'courier_unavailable', 'The labels could not be fetched from the courier.', $retryable)
                : new ApiError(409, 'label_not_ready', 'None of these shipments has a waybill to print.');
        }

        return $this->bundle($paths) + ['skipped' => array_keys($failed)];
    }

    /**
     * Fetches (or reads the stored copy of) each waybill.
     *
     * @param  Collection<int, Shipment>  $shipments  in print order
     * @return array{paths: array<string, string>, failed: array<string, ApiError>} both keyed by public id, in order
     */
    public function collect(Collection $shipments): array
    {
        $paths = [];
        $failed = [];
        foreach ($shipments as $s) {
            try {
                $paths[$s->public_id] = Storage::disk('local')->path($this->labels->get($s)->path);
            } catch (ApiError $e) {
                $failed[$s->public_id] = $e;
            }
        }

        return ['paths' => $paths, 'failed' => $failed];
    }

    /**
     * One PDF of the given waybill files (a ZIP when they cannot be merged).
     *
     * @param  non-empty-array<string, string>  $paths  absolute paths in print order
     * @return array{bytes: string, type: 'pdf'|'zip'}
     */
    public function bundle(array $paths): array
    {
        if (count($paths) === 1) {
            return ['bytes' => (string) file_get_contents(reset($paths)), 'type' => 'pdf'];
        }

        $merged = $this->merger->merge($paths);
        try {
            $bytes = (string) file_get_contents($merged['path']);
        } finally {
            @unlink($merged['path']);
        }

        return ['bytes' => $bytes, 'type' => $merged['type']];
    }
}
