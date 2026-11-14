<?php

namespace App\Shipments\Quotes;

use App\Couriers\Contracts\QuotesWithRefusal;
use App\Couriers\CourierOrder;
use App\Couriers\CourierRegistry;
use App\Models\CourierAccount;
use App\Models\Shipment;
use App\Shipments\CourierOptions;
use App\Shipments\ShipmentCreator;
use App\Support\Money;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One account's answer to POST /quotes: the courier's own figure, or why it refuses, or nothing. Never a guessed
 * amount. The single-account answer and each entry of the all-couriers answer are built here.
 */
final class AccountQuote
{
    /**
     * @param  array<string, mixed>  $data  QuoteRequest::validated()
     * @return array{fee: array{amount: string, currency: string, label: ?string, odz: ?bool, remote_area_fee: ?string}|null, refusal: array{code: string, reason: string}|null}
     */
    public function quote(CourierAccount $account, array $data): array
    {
        $shipment = new Shipment(ShipmentCreator::attributes($data));

        try {
            $courier = CourierRegistry::for($account);
            $fee = $courier->estimateFee(CourierOrder::fromApi($shipment), CourierOptions::for($shipment));
        } catch (Throwable $e) {
            Log::warning('Quote failed', ['account' => $account->public_id, 'exception' => $e::class]);
            $courier = null;
            $fee = null;
        }

        if ($fee === null || ! isset($fee->amount) || ! is_numeric($fee->amount)) {
            return ['fee' => null, 'refusal' => $courier instanceof QuotesWithRefusal ? $this->refusal($courier, $shipment) : null];
        }

        return ['fee' => [
            'amount' => number_format((float) $fee->amount, 2, '.', ''),
            'currency' => (string) ($fee->currency ?? 'PHP'),
            'label' => isset($fee->label) ? (string) $fee->label : null,
            // Information only, from the courier's own answer; null when it said nothing (never a refusal).
            'odz' => isset($fee->odz) && is_bool($fee->odz) ? $fee->odz : null,
            'remote_area_fee' => Money::decimal($fee->remote_area_fee ?? null),
        ], 'refusal' => null];
    }

    /**
     * A runner's job (`{account_id, data}`), quoted when the account is still active; null otherwise.
     *
     * @param  array<string, mixed>  $job
     * @return array<string, mixed>|null
     */
    public static function forJob(array $job): ?array
    {
        $account = is_string($job['account_id'] ?? null)
            ? CourierAccount::query()->where('public_id', $job['account_id'])->first()
            : null;
        if ($account === null || ! $account->isActive() || ! is_array($job['data'] ?? null)) {
            return null;
        }

        return app(self::class)->quote($account, $job['data']);
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

        // Only a refusal with a code is answered: SPX's. QuadX and LBC keep answering no figure alone.
        return $quote->refusal?->code === null ? null : ['code' => $quote->refusal->code, 'reason' => $quote->refusal->message()];
    }
}
