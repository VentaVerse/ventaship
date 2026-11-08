<?php

namespace App\Http\Controllers\Ui;

use App\Couriers\CourierOrder;
use App\Couriers\CourierRegistry;
use App\Http\Controllers\Controller;
use App\Models\CourierAccount;
use App\Models\Shipment;
use App\Shipments\CourierFormFields;
use App\Shipments\ShipmentCreator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/** The Add / Edit shipment form's on-demand lists: SPX pickup times (a courier call) and LBC pickup dates (none). */
class ShipmentOptionsController extends Controller
{
    private const FAILED = "Couldn't load.";

    private const PICKUP_KEYS = ['contact_name', 'contact_phone', 'address_1', 'address_2', 'barangay', 'district', 'city', 'state', 'postcode'];

    /**
     * GET /shipments/options/spx-slots?account_id=…&pickup[…]: SPX's pickup times for the sender address. A courier
     * failure, or no times at all (the adapter answers none when SPX fails), is a 502 the form offers to retry.
     */
    public function spxSlots(Request $request): JsonResponse
    {
        $account = CourierAccount::query()
            ->where('public_id', (string) $request->query('account_id'))
            ->where('courier', 'spx')
            ->first();
        if ($account === null) {
            return response()->json(['error' => self::FAILED], 404);
        }

        $shipment = new Shipment(ShipmentCreator::attributes(['pickup' => $this->pickup($request)]));

        try {
            $slots = CourierRegistry::for($account)->getPickupSlots(CourierOrder::fromApi($shipment));
        } catch (Throwable $e) {
            Log::warning('SPX pickup times failed', ['account' => $account->public_id, 'error' => $e->getMessage()]);

            return response()->json(['error' => self::FAILED], 502);
        }

        $answer = [];
        foreach ($slots as $slot) {
            if (is_array($slot) && isset($slot['value'], $slot['label']) && empty($slot['disabled'])) {
                $answer[] = ['value' => (string) $slot['value'], 'label' => (string) $slot['label']];
            }
        }

        return $answer === []
            ? response()->json(['error' => self::FAILED], 502)
            : response()->json(['slots' => $answer]);
    }

    /** GET /shipments/options/lbc-dates: the next business days, Monday to Saturday. No courier call. */
    public function lbcDates(): JsonResponse
    {
        return response()->json(['dates' => CourierFormFields::lbcDates()]);
    }

    /** @return array<string, string|array<string, string>> the posted sender address, strings only */
    private function pickup(Request $request): array
    {
        $raw = $request->query('pickup');
        $raw = is_array($raw) ? $raw : [];
        $pickup = [];
        foreach (self::PICKUP_KEYS as $key) {
            if (is_string($raw[$key] ?? null)) {
                $pickup[$key] = mb_substr($raw[$key], 0, 255);
            }
        }
        $codes = is_array($raw['codes'] ?? null) ? array_filter($raw['codes'], 'is_string') : [];
        $pickup['codes'] = array_map(fn ($c) => mb_substr($c, 0, 32), array_intersect_key($codes, array_flip(['state', 'city', 'barangay'])));

        return $pickup;
    }
}
