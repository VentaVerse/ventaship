<?php

namespace App\Couriers\Lbc;

use App\Couriers\AccountSettings;
use App\Couriers\CourierOrder;
use App\Couriers\LiveBookingBlocked;
use App\Couriers\PickupPoint;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use App\Couriers\TrackingNumbers\IssuedTrackingNumber;
use App\Couriers\TrackingNumbers\TrackingNumberSeriesService;
use Illuminate\Support\Facades\Log;

/**
 * One LBC pickup request, from a shipment to LBC's answer, for one courier
 * account.
 *
 *   prepare()  reads the account, resolves both addresses through LBC's
 *              address search and serviceability check, and lists anything
 *              that would stop the booking. Read-only calls only.
 *   preview()  the request as it would be sent, with the tracking number it
 *              would use, without taking that number. Sends nothing.
 *   send()     reserves the next tracking number, sends the request once and
 *              settles the number by LBC's answer: spent when LBC saved it
 *              or the outcome is unknown, released when LBC definitely
 *              rejected it. Never retries.
 */
class LbcPickupService
{
    public const COURIER = 'lbc';

    public function __construct(
        private readonly AccountSettings $settings,
        private readonly LbcClient $client,
        private readonly LbcAreaResolver $areas,
        private readonly TrackingNumberSeriesService $series,
        private readonly LbcPickupRequestBuilder $builder = new LbcPickupRequestBuilder(),
    ) {
    }

    public function prepare(
        CourierOrder $order,
        PickupPoint $from,
        LbcParcel $parcel,
        CarbonInterface $preferredAt,
        string $mode = LbcPickupRequestBuilder::MODE_REGULAR,
        ?CarbonInterface $transactionAt = null,
    ): LbcPickupDraft {
        $account = LbcAccount::fromSettings($this->settings);

        $origin = $this->areas->resolve($from->barangay, $from->city, $from->state, $from->state_code);
        $destination = $this->areas->resolve($order->shipping_barangay, $order->shipping_city, $order->shipping_state, $order->shipping_state_code);

        $blockers = [];
        foreach (['pickup' => $origin, 'delivery' => $destination] as $which => $area) {
            foreach ($area->problems as $problem) {
                $blockers[] = "The {$which} address: {$problem}";
            }
            if ($area->serviceable === false) {
                $blockers[] = "LBC does not serve the {$which} address ({$area->status}).";
            } elseif ($area->serviceable === null) {
                $blockers[] = "LBC has not confirmed it serves the {$which} address.";
            }
            if ($area->areaCode === null) {
                $blockers[] = "The LBC area code of the {$which} address is not known.";
            }
        }

        $now = CarbonImmutable::now(LbcPickupRequestBuilder::TIMEZONE);
        if ($preferredAt->lessThanOrEqualTo($now)) {
            $blockers[] = 'The preferred date must be in the future.';
        }

        return new LbcPickupDraft(
            order: $order,
            from: $from,
            parcel: $parcel,
            account: $account,
            origin: $origin,
            destination: $destination,
            transactionAt: $transactionAt ?? $now,
            preferredAt: $preferredAt,
            mode: $mode,
            blockers: array_values(array_unique($blockers)),
        );
    }

    /**
     * @return array{issued: IssuedTrackingNumber, fields: array<string,string>}
     */
    public function preview(LbcPickupDraft $draft): array
    {
        $issued = $this->series->peek($this->settings->account());

        return ['issued' => $issued, 'fields' => $this->fieldsFor($draft, $issued->number)];
    }

    /**
     * @return array{issued: IssuedTrackingNumber, fields: array<string,string>, result: LbcPickupResult}
     * @throws LbcException when the draft has blockers; nothing is reserved or sent
     */
    public function send(LbcPickupDraft $draft): array
    {
        if ($draft->blockers !== []) {
            throw new LbcException('Not sent: ' . implode(' ', $draft->blockers));
        }

        // Build once with a placeholder first, so a missing value refuses
        // before any number is reserved.
        $this->fieldsFor($draft, 1);

        $issued = $this->series->reserve(
            $this->settings->account(),
            $draft->order->id > 0 ? (int) $draft->order->id : null,
        );

        try {
            $fields = $this->fieldsFor($draft, $issued->number);
            $xml = $this->builder->toXml($fields);
        } catch (\Throwable $e) {
            // The request was never built, so LBC has nothing: the number goes back.
            $this->series->release($issued, 'Not sent: ' . $e->getMessage());

            throw $e;
        }

        try {
            $result = $this->client->insertPickupRequest($xml);
        } catch (LiveBookingBlocked|LbcException $e) {
            // Only thrown before the request leaves (a live lock off, the key
            // missing): LBC has nothing, so the number goes back.
            $this->series->release($issued, 'Not sent: ' . $e->getMessage());

            throw $e;
        } catch (\Throwable $e) {
            // Anything else may have happened after the request left. LBC may
            // hold the pickup, so the number is never handed to another parcel.
            Log::warning('LBC pickup request failed after it may have been sent', ['error' => $e->getMessage()]);
            $result = LbcPickupResult::transportFailure('The LBC answer could not be read: ' . $e->getMessage());
        }

        if ($result->outcome === LbcPickupResult::REJECTED) {
            $this->series->release($issued, 'Rejected: ' . $result->summary());
        } else {
            $this->series->spend($issued, ucfirst($result->outcome) . ': ' . $result->summary());
        }

        Log::info('LBC pickup request sent', [
            'account' => $this->settings->account()->public_id,
            'shipment_id' => $draft->order->id,
            'tracking_no' => $issued->number,
            'outcome' => $result->outcome,
            'code' => $result->code(),
            'http_status' => $result->httpStatus,
        ]);

        return ['issued' => $issued, 'fields' => $fields, 'result' => $result];
    }

    /** @return array<string,string> */
    public function fieldsFor(LbcPickupDraft $draft, int $trackingNo): array
    {
        return $this->builder->fields(
            $draft->order,
            $draft->from,
            $draft->parcel,
            $draft->origin,
            $draft->destination,
            $draft->account,
            $trackingNo,
            $draft->transactionAt,
            $draft->preferredAt,
            $draft->mode,
        );
    }

    /**
     * A date a number of business days (Monday to Saturday) from now, at a
     * set time, Asia/Manila. LBC publishes no pickup cutoff, so a few days'
     * lead leaves room to call LBC before the date.
     */
    public static function businessDaysAhead(int $days, string $time = '10:00:00', ?CarbonInterface $from = null): CarbonImmutable
    {
        $date = CarbonImmutable::instance($from ?? CarbonImmutable::now())->setTimezone(LbcPickupRequestBuilder::TIMEZONE);

        for ($added = 0; $added < $days;) {
            $date = $date->addDay();
            if (!$date->isSunday()) {
                $added++;
            }
        }

        [$h, $m, $s] = array_map('intval', array_pad(explode(':', $time), 3, 0));

        return $date->setTime($h, $m, $s);
    }
}
