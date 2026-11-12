<?php

namespace App\Http\Controllers\Api\V1;

use App\Couriers\AccountResolver;
use App\Couriers\Contracts\QuotesWithRefusal;
use App\Couriers\CourierOrder;
use App\Couriers\CourierRegistry;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\QuoteRequest;
use App\Models\Shipment;
use App\Shipments\CourierOptions;
use App\Shipments\ShipmentCreator;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

class QuotesController extends Controller
{
    /**
     * POST /quotes: the courier's own figure, or `data: null` when it has none. Never a guessed amount. When the
     * courier has no figure because it refuses the shipment, the answer also carries `refusal: {code, reason}`.
     */
    public function __invoke(QuoteRequest $request): JsonResponse
    {
        $data = $request->validated();
        $account = AccountResolver::resolve($data['account_id'] ?? null, $data['courier'] ?? null);
        AccountResolver::assertActive($account);

        $shipment = new Shipment(ShipmentCreator::attributes($data));

        try {
            $courier = CourierRegistry::for($account);
            $fee = $courier->estimateFee(CourierOrder::fromApi($shipment), CourierOptions::for($shipment));
        } catch (Throwable $e) {
            Log::warning('Quote failed', ['account' => $account->public_id, 'error' => $e->getMessage()]);
            $courier = null;
            $fee = null;
        }

        if ($fee === null || ! isset($fee->amount) || ! is_numeric($fee->amount)) {
            $refusal = $courier instanceof QuotesWithRefusal ? $this->refusal($courier, $shipment) : null;

            return response()->json($refusal === null ? ['data' => null] : ['data' => null, 'refusal' => $refusal]);
        }

        return response()->json(['data' => [
            'amount' => number_format((float) $fee->amount, 2, '.', ''),
            'currency' => (string) ($fee->currency ?? 'PHP'),
            'label' => isset($fee->label) ? (string) $fee->label : null,
            // Information only, from the courier's own answer; null when it said nothing (never a refusal).
            'odz' => isset($fee->odz) && is_bool($fee->odz) ? $fee->odz : null,
            'remote_area_fee' => Money::decimal($fee->remote_area_fee ?? null),
        ]]);
    }

    /**
     * Why the courier has no figure, when it is because it refuses the shipment (asked through the same final check
     * the dashboard uses) and the refusal has a code. Null for anything else: an outage, missing credentials, a
     * figure it simply did not give.
     *
     * @return array{code: string, reason: string}|null
     */
    private function refusal(QuotesWithRefusal $courier, Shipment $shipment): ?array
    {
        try {
            $quote = $courier->quoteFee(CourierOrder::fromApi($shipment), CourierOptions::for($shipment));
        } catch (Throwable $e) {
            Log::warning('Quote refusal check failed', ['exception' => $e::class]);

            return null;
        }

        // Only a refusal with a code is answered: SPX's. QuadX and LBC keep answering `data: null` alone.
        return $quote->refusal?->code === null ? null : ['code' => $quote->refusal->code, 'reason' => $quote->refusal->message()];
    }
}
