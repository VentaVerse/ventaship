<?php

namespace App\Http\Controllers\Api\V1;

use App\Couriers\AccountResolver;
use App\Couriers\CourierOrder;
use App\Couriers\PickupPoint;
use App\Couriers\ServiceabilityService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CoverageRequest;
use App\Models\Shipment;
use App\Shipments\ShipmentCreator;
use Illuminate\Http\JsonResponse;

class CoverageController extends Controller
{
    /**
     * POST /coverage: will this account carry from the pickup point to each destination? Each destination is one
     * ServiceabilityService check (5-second budget, a day's cache for definite answers); a failure is `unknown`.
     */
    public function __invoke(CoverageRequest $request): JsonResponse
    {
        $data = $request->validated();
        $account = AccountResolver::resolve($data['account_id'] ?? null, $data['courier'] ?? null);
        AccountResolver::assertActive($account);

        $from = PickupPoint::fromArray($data['pickup']);
        $payment = ['method' => $data['payment']['method'] ?? 'prepaid'];
        $parcel = self::parcel($data['parcel'] ?? null);

        $answers = [];
        foreach ($data['destinations'] as $destination) {
            $shipment = new Shipment(ShipmentCreator::attributes(['recipient' => $destination['recipient'], 'payment' => $payment]));
            $verdict = ServiceabilityService::check($account, CourierOrder::fromApi($shipment), $from, $parcel);

            $answers[] = [
                'ref' => $destination['ref'],
                'verdict' => $verdict->status,
                'kind' => $verdict->kind,
                'code' => $verdict->code,
                'reason' => $verdict->reason,
                'odz' => $verdict->odz,
            ];
        }

        return response()->json(['data' => $answers]);
    }

    /**
     * The optional `parcel` in the couriers' option shape (createShipment()'s $opts): only what was sent, so a request
     * without one is checked exactly as before.
     *
     * @return array<string, string|float>
     */
    private static function parcel(mixed $parcel): array
    {
        if (! is_array($parcel)) {
            return [];
        }

        return array_filter([
            'parcel' => is_string($parcel['type'] ?? null) && trim($parcel['type']) !== '' ? trim($parcel['type']) : null,
            'weight_kg' => is_numeric($parcel['weight_kg'] ?? null) ? (float) $parcel['weight_kg'] : null,
            'length_cm' => is_numeric($parcel['length_cm'] ?? null) ? (float) $parcel['length_cm'] : null,
            'width_cm' => is_numeric($parcel['width_cm'] ?? null) ? (float) $parcel['width_cm'] : null,
            'height_cm' => is_numeric($parcel['height_cm'] ?? null) ? (float) $parcel['height_cm'] : null,
        ], fn ($v) => $v !== null);
    }
}
