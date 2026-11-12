<?php

namespace App\Shipments;

use App\Couriers\CourierOrder;
use App\Couriers\CourierRegistry;
use App\Couriers\Spx\SpxFulfillment;
use App\Couriers\Support\CourierRefusal;
use App\Models\Shipment;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Editing an SPX booking (SPX 1.7 Update Order): while SPX still has the parcel as 1001 Pending Pickup, at most
 * 3 times. Only the recipient, the delivery note, the item name, the parcel and the pickup time change; the courier,
 * handover, who pays shipping, COD and insurance stay as booked. SPX is asked first: a refusal changes nothing here.
 * After an update the stored waybill is deleted, so the next print fetches SPX's new one.
 */
final class SpxBookingEdits
{
    public const MAX = 3;

    /** SPX's status for an order it has created and not yet collected. */
    public const PENDING_PICKUP = '1001';

    private const LOCK_SECONDS = 120;

    public static function made(Shipment $s): int
    {
        $updates = data_get($s->meta, 'booking_updates');

        return is_array($updates) ? count($updates) : 0;
    }

    public static function left(Shipment $s): int
    {
        return max(0, self::MAX - self::made($s));
    }

    public static function allowed(Shipment $s): bool
    {
        return $s->courier === 'spx'
            && $s->state === 'booked'
            && (string) $s->courier_status === self::PENDING_PICKUP
            && $s->cancel_state === null
            && $s->pii_purged_at === null
            && is_array($s->recipient)
            && (string) $s->tracking_number !== ''
            && self::left($s) > 0;
    }

    /**
     * Sends the edited booking to SPX and, when SPX takes it, stores it. Null on success, else why not.
     *
     * @param  array<string, mixed>  $data  UpdateSpxBookingRequest::shipmentData()
     */
    public function update(Shipment $shipment, array $data): ?CourierRefusal
    {
        $lock = Cache::lock("shipment:{$shipment->id}", self::LOCK_SECONDS);
        if (! $lock->get()) {
            return new CourierRefusal('This shipment is busy', 'Try again in a moment.');
        }

        try {
            $s = $shipment->fresh(['courierAccount']);
            if ($s === null || ! self::allowed($s) || $s->courierAccount === null) {
                return new CourierRefusal('This booking can no longer be edited');
            }

            $edited = $this->edited($s, $data);
            $courier = CourierRegistry::for($s->courierAccount);
            if (! $courier instanceof SpxFulfillment) {
                return new CourierRefusal('This booking can no longer be edited');
            }

            $answer = $courier->updateBooking(CourierOrder::fromApi($edited), CourierOptions::for($edited));
            if (($answer['ok'] ?? false) !== true) {
                return $answer['refusal'];
            }

            $this->store($s, $edited);

            return null;
        } finally {
            $lock->release();
        }
    }

    /** The shipment as it would be after the edit: a copy, never saved by itself. */
    private function edited(Shipment $s, array $data): Shipment
    {
        $new = ShipmentCreator::attributes($data);
        $options = is_array($s->options) ? $s->options : [];
        $parcel = is_array($new['options']['parcel'] ?? null) ? $new['options']['parcel'] : [];
        // The item category is not edited here: the booked one stays.
        if (isset($options['parcel']['category'])) {
            $parcel['category'] = $options['parcel']['category'];
        }
        $options['parcel'] = $parcel;
        if ($s->method === 'pickup' && is_string($new['options']['pickup_slot'] ?? null)) {
            $options['pickup_slot'] = $new['options']['pickup_slot'];
        }

        $recipient = is_array($new['recipient'] ?? null) ? $new['recipient'] : [];
        $email = data_get($s->recipient, 'email');
        if (filled($email) && blank($recipient['email'] ?? null)) {
            $recipient['email'] = $email;
        }

        $copy = clone $s;
        $copy->forceFill([
            'recipient' => $recipient,
            'options' => $options,
            'courier_note' => $new['courier_note'],
            'items' => $new['items'],
            'items_count' => $new['items_count'],
            'items_summary' => $new['items_summary'],
        ]);

        return $copy;
    }

    private function store(Shipment $s, Shipment $edited): void
    {
        $paths = [];
        try {
            DB::transaction(function () use ($s, $edited, &$paths) {
                $row = Shipment::query()->whereKey($s->getKey())->lockForUpdate()->firstOrFail();
                $meta = is_array($row->meta) ? $row->meta : [];
                $updates = is_array($meta['booking_updates'] ?? null) ? array_values($meta['booking_updates']) : [];
                $updates[] = ['at' => now()->toIso8601String()];
                $meta['booking_updates'] = $updates;

                $row->forceFill([
                    'recipient' => $edited->recipient,
                    'options' => $edited->options,
                    'courier_note' => $edited->courier_note,
                    'items' => $edited->items,
                    'items_count' => $edited->items_count,
                    'items_summary' => $edited->items_summary,
                    'meta' => $meta,
                ])->save();

                // SPX's waybill changes with the order: the stored one goes, and the next print fetches the new one.
                $paths = $row->labels()->pluck('path')->all();
                $row->labels()->delete();
            });
        } catch (Throwable $e) {
            // SPX has the update; what it holds is now the truth, and the next tracking poll does not repair this.
            Log::critical('SPX took a booking update that could not be saved', ['shipment' => $s->public_id, 'exception' => $e::class]);

            throw $e;
        }

        Storage::disk('local')->delete($paths);
    }
}
