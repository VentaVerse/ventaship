<?php

namespace App\Http\Controllers\Api\V1;

use App\Couriers\AccountResolver;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\QuoteRequest;
use App\Shipments\Quotes\AccountQuote;
use App\Shipments\Quotes\AllCouriersQuote;
use Illuminate\Http\JsonResponse;

class QuotesController extends Controller
{
    /**
     * POST /quotes: the courier's own figure, or `data: null` when it has none. Never a guessed amount. When the
     * courier has no figure because it refuses the shipment, the answer also carries `refusal: {code, reason}`.
     *
     * With `couriers: "all"` (and no account_id or courier), every active account is asked at once within a time
     * budget, and `data` is a list with one entry per account (AllCouriersQuote).
     */
    public function __invoke(QuoteRequest $request, AccountQuote $quotes, AllCouriersQuote $all): JsonResponse
    {
        // The budget counts from the moment the request reached the server, not from here (never more than a second
        // back, in case that time is not the request's own).
        $now = microtime(true);
        $arrived = $request->server('REQUEST_TIME_FLOAT');
        $startedAt = is_numeric($arrived) ? max((float) $arrived, $now - 1.0) : $now;
        $data = $request->validated();

        if (($data['couriers'] ?? null) === 'all') {
            $budget = ((int) ($data['budget_ms'] ?? AllCouriersQuote::DEFAULT_BUDGET_MS)) / 1000;
            unset($data['couriers'], $data['budget_ms']);
            $parcelType = $data['parcel_type'] ?? null;
            unset($data['parcel_type']);

            // A little is kept back to build and send the answer, so the whole call returns within the budget.
            return response()->json(['data' => $all->quote($data, $startedAt + $budget - AllCouriersQuote::RESPONSE_RESERVE_SECONDS, $parcelType)]);
        }

        $account = AccountResolver::resolve($data['account_id'] ?? null, $data['courier'] ?? null);
        AccountResolver::assertActive($account);

        $answer = $quotes->quote($account, $data);
        if ($answer['fee'] === null) {
            return response()->json($answer['refusal'] === null ? ['data' => null] : ['data' => null, 'refusal' => $answer['refusal']]);
        }

        return response()->json(['data' => $answer['fee']]);
    }
}
