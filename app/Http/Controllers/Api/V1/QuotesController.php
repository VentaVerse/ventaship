<?php

namespace App\Http\Controllers\Api\V1;

use App\Couriers\AccountResolver;
use App\Couriers\CourierOrder;
use App\Couriers\CourierRegistry;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\QuoteRequest;
use App\Models\Shipment;
use App\Shipments\CourierOptions;
use App\Shipments\ShipmentCreator;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

class QuotesController extends Controller
{
    /** POST /quotes: the courier's own figure, or `data: null` when it has none. Never a guessed amount. */
    public function __invoke(QuoteRequest $request): JsonResponse
    {
        $data = $request->validated();
        $account = AccountResolver::resolve($data['account_id'] ?? null, $data['courier'] ?? null);
        AccountResolver::assertActive($account);

        $shipment = new Shipment(ShipmentCreator::attributes($data));

        try {
            $fee = CourierRegistry::for($account)->estimateFee(CourierOrder::fromApi($shipment), CourierOptions::for($shipment));
        } catch (Throwable $e) {
            Log::warning('Quote failed', ['account' => $account->public_id, 'error' => $e->getMessage()]);
            $fee = null;
        }

        if ($fee === null || ! isset($fee->amount) || ! is_numeric($fee->amount)) {
            return response()->json(['data' => null]);
        }

        return response()->json(['data' => [
            'amount' => number_format((float) $fee->amount, 2, '.', ''),
            'currency' => (string) ($fee->currency ?? 'PHP'),
            'label' => isset($fee->label) ? (string) $fee->label : null,
        ]]);
    }
}
