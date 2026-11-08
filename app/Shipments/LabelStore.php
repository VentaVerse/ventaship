<?php

namespace App\Shipments;

use App\Api\ApiError;
use App\Couriers\CourierRegistry;
use App\Couriers\Support\LabelHosts;
use App\Models\Shipment;
use App\Models\ShipmentLabel;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * A shipment's waybill: fetched from the courier on first use, kept on the private local disk at
 * labels/{courier}/{tracking}.pdf and served from there afterwards (a repeat print makes no courier call).
 */
final class LabelStore
{
    public const FORMAT = 'pdf';

    private const MAX_BYTES = 10 * 1024 * 1024;

    private const SAFE_NAME = '/^[A-Za-z0-9_-]{1,64}$/';

    /** @throws ApiError 409 label_not_ready, 502 courier_unavailable */
    public function get(Shipment $s): ShipmentLabel
    {
        $account = $s->courierAccount;
        $tracking = (string) $s->tracking_number;
        if ($s->state !== 'booked' || $tracking === '' || $account === null) {
            throw new ApiError(409, 'label_not_ready', 'This shipment has no waybill yet.');
        }

        $courier = (string) $account->courier;
        if (preg_match(self::SAFE_NAME, $courier) !== 1 || preg_match(self::SAFE_NAME, $tracking) !== 1) {
            Log::warning('Label refused: unsafe courier or tracking number for a storage path', ['shipment' => $s->public_id]);

            throw $this->unavailable(false);
        }

        $disk = Storage::disk('local');
        $path = "labels/{$courier}/{$tracking}.pdf";

        if (($label = $this->stored($s, $disk, $path)) !== null) {
            return $label;
        }

        // One fetch per shipment at a time, so two prints do not both call the courier.
        $lock = Cache::lock("label:{$s->id}", 60);
        try {
            $lock->block(30);
        } catch (Throwable) {
            throw $this->unavailable(true);
        }

        try {
            return $this->stored($s, $disk, $path) ?? $this->fetch($s, $disk, $path);
        } finally {
            $lock->release();
        }
    }

    private function stored(Shipment $s, Filesystem $disk, string $path): ?ShipmentLabel
    {
        $label = ShipmentLabel::query()->where('shipment_id', $s->id)->where('format', self::FORMAT)->first();

        return $label !== null && $label->path === $path && $disk->exists($path) ? $label : null;
    }

    private function fetch(Shipment $s, Filesystem $disk, string $path): ShipmentLabel
    {
        $account = $s->courierAccount;

        // No transaction is held while the courier is called.
        try {
            $result = Context::scope(
                fn () => CourierRegistry::for($account)->printLabel((string) $s->tracking_number, self::FORMAT),
                ['account_id' => $account->getKey(), 'shipment_id' => $s->id, 'operation' => 'print_label'],
            );
        } catch (Throwable $e) {
            Log::warning('Label fetch failed', ['shipment' => $s->public_id, 'exception' => $e::class]);

            throw $this->unavailable(true);
        }

        if (! is_array($result) || ($result['success'] ?? false) !== true) {
            Log::warning('Label refused by the courier', ['shipment' => $s->public_id]);

            throw $this->unavailable(! (is_array($result) && ($result['unsafe'] ?? false) === true));
        }

        $bytes = $this->bytes($s, $disk, $path, $result, (string) $account->courier);
        if (! str_starts_with($bytes, '%PDF-') || strlen($bytes) > self::MAX_BYTES) {
            Log::warning('Label is not a usable PDF', ['shipment' => $s->public_id]);

            throw $this->unavailable(true);
        }

        $disk->put($path, $bytes);

        return ShipmentLabel::query()->updateOrCreate(
            ['shipment_id' => $s->id, 'format' => self::FORMAT],
            ['path' => $path, 'bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'fetched_at' => now()],
        );
    }

    /** @param  array<string, mixed>  $result  printLabel()'s answer */
    private function bytes(Shipment $s, Filesystem $disk, string $path, array $result, string $courier): string
    {
        // SPX and LBC store the PDF themselves and answer its path; only the path we expect is read.
        if (isset($result['path'])) {
            if ($result['path'] !== $path || ! $disk->exists($path)) {
                Log::warning('Courier stored its label somewhere unexpected', ['shipment' => $s->public_id]);

                throw $this->unavailable(false);
            }

            return (string) $disk->get($path);
        }

        if (isset($result['content']) && is_string($result['content'])) {
            return $result['content'];
        }

        $url = $result['url'] ?? null;
        if (! LabelHosts::allows($courier, $url)) {
            Log::warning('Label link refused: host not on the allow-list', [
                'shipment' => $s->public_id,
                'host' => is_string($url) ? parse_url($url, PHP_URL_HOST) : null,
            ]);

            throw $this->unavailable(false);
        }

        try {
            $response = Http::timeout(30)->withoutRedirecting()->get($url);
        } catch (Throwable $e) {
            Log::warning('Label download failed', ['shipment' => $s->public_id, 'exception' => $e::class]);

            throw $this->unavailable(true);
        }

        if (! $response->successful()) {
            Log::warning('Label download refused', ['shipment' => $s->public_id, 'status' => $response->status()]);

            throw $this->unavailable(true);
        }

        return $response->body();
    }

    private function unavailable(bool $retryable): ApiError
    {
        return new ApiError(502, 'courier_unavailable', 'The label could not be fetched from the courier.', $retryable);
    }
}
