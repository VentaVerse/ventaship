<?php

namespace App\Http\Controllers\Api\V1;

use App\Couriers\AccountResolver;
use App\Couriers\CourierOrder;
use App\Couriers\CourierRegistry;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PickupSlotsRequest;
use App\Models\Shipment;
use App\Shipments\ShipmentCreator;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

class PickupSlotsController extends Controller
{
    /** POST /pickup-slots: `value` is the courier's own and goes back unchanged as `pickup_slot`. */
    public function __invoke(PickupSlotsRequest $request): JsonResponse
    {
        $data = $request->validated();
        $account = AccountResolver::resolve($data['account_id'] ?? null, $data['courier'] ?? null);
        AccountResolver::assertActive($account);

        $shipment = new Shipment(ShipmentCreator::attributes($data));

        try {
            $slots = CourierRegistry::for($account)->getPickupSlots(CourierOrder::fromApi($shipment));
        } catch (Throwable $e) {
            Log::warning('Pickup slots failed', ['account' => $account->public_id, 'error' => $e->getMessage()]);
            $slots = [];
        }

        $answer = [];
        foreach ($slots as $slot) {
            if (! is_array($slot) || ! isset($slot['value'], $slot['label']) || ! empty($slot['disabled'])) {
                continue;
            }
            $answer[] = ['value' => (string) $slot['value'], 'label' => (string) $slot['label'], 'date' => $this->date($slot)];
        }

        return response()->json(['data' => $answer]);
    }

    /** The calendar day a slot falls on: a Y-m-d value (LBC), or the pickup_time timestamp inside it (SPX). */
    private function date(array $slot): ?string
    {
        if (isset($slot['date']) && is_string($slot['date'])) {
            return $slot['date'];
        }

        $value = (string) $slot['value'];
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            return $value;
        }

        $decoded = json_decode($value, true);
        if (is_array($decoded) && is_numeric($decoded['pickup_time'] ?? null)) {
            return Carbon::createFromTimestamp((int) $decoded['pickup_time'], config('app.timezone'))->format('Y-m-d');
        }

        return null;
    }
}
