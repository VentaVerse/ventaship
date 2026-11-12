<?php

namespace App\Shipments;

use App\Api\ApiError;
use App\Couriers\AccountResolver;
use App\Couriers\Contracts\QuotesWithRefusal;
use App\Couriers\CourierOrder;
use App\Couriers\CourierRegistry;
use App\Couriers\Support\FeeQuote;
use App\Models\Shipment;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The final check before a dashboard booking: the courier's fee for the shipment exactly as it would be booked, or
 * its reason to refuse it. Nothing is saved. The shipment is built unsaved from the same validated input and the
 * same builders the booking uses (ShipmentCreator::attributes, CourierOrder::fromApi, CourierOptions::for).
 *
 * A courier without QuotesWithRefusal answers through estimateFee(), which never refuses. Any exception is no answer.
 */
final class BookingCheck
{
    /**
     * @param  array<string, mixed>  $data  ShipmentFormRequest::shipmentData()
     * @param  Shipment|null  $editing  the stored shipment an edit books (its courier reference is reused)
     *
     * @throws ApiError 422 account_not_found / account_misconfigured
     */
    public function quote(array $data, ?Shipment $editing = null): FeeQuote
    {
        $account = AccountResolver::resolve($data['account_id'] ?? null, $data['courier'] ?? null);
        AccountResolver::assertBookable($account);

        $shipment = new Shipment(ShipmentCreator::attributes($data));
        $shipment->forceFill([
            'courier' => $account->courier,
            'courier_account_id' => $account->getKey(),
            'courier_ref' => $editing?->courier_ref,
        ])->setRelation('courierAccount', $account);

        try {
            $courier = CourierRegistry::for($account);
            $order = CourierOrder::fromApi($shipment);
            $options = CourierOptions::for($shipment);

            return Context::scope(
                fn () => $courier instanceof QuotesWithRefusal
                    ? $courier->quoteFee($order, $options)
                    : FeeQuote::fromEstimate($courier->estimateFee($order, $options)),
                ['account_id' => $account->getKey(), 'operation' => 'booking_check'],
            );
        } catch (Throwable $e) {
            Log::warning('Booking check failed', ['account' => $account->public_id, 'exception' => $e::class]);

            return FeeQuote::unavailable();
        }
    }

    /**
     * The same check, for the booking itself: a refusal stops it before anything is saved.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws BookingRefused
     * @throws ApiError
     */
    public function assertBookable(array $data, ?Shipment $editing = null): void
    {
        $quote = $this->quote($data, $editing);
        if ($quote->refusal !== null) {
            // The courier's own words can echo the recipient back: never logged.
            Log::info('Booking refused by the courier before saving', ['account_id' => $data['account_id'] ?? null, 'refusal' => $quote->refusal->title]);

            throw new BookingRefused($quote->refusal, $quote->courierText);
        }
    }
}
