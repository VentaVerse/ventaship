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

        $answers = [];
        foreach ($data['destinations'] as $destination) {
            $shipment = new Shipment(ShipmentCreator::attributes(['recipient' => $destination['recipient'], 'payment' => $payment]));
            $verdict = ServiceabilityService::check($account, CourierOrder::fromApi($shipment), $from);

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
}
