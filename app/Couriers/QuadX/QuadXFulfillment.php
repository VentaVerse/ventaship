<?php

namespace App\Couriers\QuadX;

use App\Couriers\AccountSettings;
use App\Couriers\CourierOrder;
use App\Couriers\Contracts\Courier;
use App\Couriers\Contracts\ProvidesPublicTrackingUrl;
use App\Couriers\Contracts\ReportsShippingCost;
use App\Couriers\PickupOrigin;
use App\Couriers\PickupPoint;
use App\Couriers\ServiceabilityService;
use App\Couriers\Support\ServiceabilityVerdict;
use App\Models\CourierAccount;

/**
 * QuadX courier (a Philippine courier, LBC-backed).
 *
 * Implements the generic fulfillment contract. All QuadX-specific knowledge
 * lives here + QuadXClient: auth, parcel categories, services, the
 * seller_payment_method field, and name-based address resolution.
 */
class QuadXFulfillment implements Courier, ProvidesPublicTrackingUrl, ReportsShippingCost
{
    /** QuadX's official status labels (docs/couriers/quadx/statuses.md); the live cache wins when it has one. */
    public const STATUS_LABELS = [
        'pending' => 'Pending',
        'for_pickup' => 'Ready for pickup',
        'picked_up' => 'Picked up',
        'failed_pickup' => 'Failed pickup',
        'failed_delivery' => 'Failed delivery',
        'in_transit' => 'In transit',
        'claimed' => 'Claimed',
        'delivered' => 'Delivered',
        'return_in_transit' => 'Returned - in transit',
        'returned' => 'Returned',
        'failed_return' => 'Failed return',
        'out_for_delivery' => 'Out for delivery',
        'confirmed' => 'Confirmed',
        'canceled' => 'Canceled',
        'for_consolidation' => 'For consolidation',
        'consolidated' => 'Consolidated',
        'for_dropoff' => 'Ready for dropoff',
        'dropped_off' => 'Dropped off',
        'for_acceptance' => 'For acceptance',
        'accepted' => 'Accepted',
        'out_for_return' => 'Out for return',
        'for_payment' => 'For payment',
        'paid' => 'Paid',
        'for_transfer' => 'For transfer',
        'received_at_pickup_hub' => 'Received at pickup hub',
        'received_at_delivery_hub' => 'Received at delivery hub',
        'received_at_delivery_area' => 'Received at delivery area',
        'at_3pl_facility' => 'At 3PL facility',
        'at_sorting_center' => 'At sorting center',
        'in_transit_to_delivery_area' => 'In transit to delivery area',
        'transferred_to_partner' => 'Transferred to partner',
        'for_return' => 'For return',
        'arrived_at_rts_hub' => 'Arrived at RTS hub',
        'arrived_at_rts_area' => 'Arrived at RTS area',
        'at_sorting_center_rts' => 'At sorting center RTS',
        'for_claims' => 'For Claims',
        'claims_in_transit' => 'Claims in transit',
        'arrived_at_claims_area' => 'Arrived at claims area',
        'disposed' => 'Disposed',
        'rejected' => 'Rejected',
        'damaged' => 'Damaged',
        'lost' => 'Lost',
        'rider_found' => 'Rider found',
        'picking_up' => 'Picking up',
        'for_delivery' => 'For delivery',
        'for_release' => 'For release',
        'out_for_pickup' => 'Out for pickup',
        'failed_pickup_attempt' => 'Failed pickup attempt',
        'runsheet_closure' => 'Runsheet closure',
        'pickup_rider_found' => 'Pickup rider found',
        'delivery_rider_found' => 'Delivery rider found',
        'for_disposal' => 'For disposal',
        'pending_disposition' => 'Pending disposition',
        'rcd_at_sorting_center' => 'RCD at sorting center',
        'rcd_in_transit' => 'RCD in transit',
        'received_at_claims_recovery_hub' => 'Received at Claims/Recovery hub',
        'dws_update' => 'DWS update',
        'searching_for_rider' => 'Searching for Rider',
    ];

    /** @return array<string, string> */
    public function statusLabels(): array
    {
        return self::STATUS_LABELS;
    }

    private QuadXClient $client;

    private QuadXLocationResolver $resolver;

    public function __construct(
        private readonly CourierAccount $account,
        private readonly AccountSettings $settings,
        ?QuadXClient $client = null,
        ?QuadXLocationResolver $resolver = null,
    ) {
        $this->client = $client ?? new QuadXClient($settings);
        $this->resolver = $resolver ?? new QuadXLocationResolver();
    }

    public function getKey(): string
    {
        return 'quadx';
    }

    public function getName(): string
    {
        return 'QuadX';
    }

    /** The account is active and holds both credentials for its mode. */
    public function isEnabled(): bool
    {
        return $this->account->status === 'active' && $this->client->isConfigured();
    }

    /** @see Courier::configurationIssues() */
    public function configurationIssues(): array
    {
        return $this->client->missingCredentialLabels();
    }

    public function getServiceTypes(): array
    {
        return [
            // Owner ruling 2026-09-03: same day sits first because it is the
            // one with a cut-off to catch, and both are named "Pickup" so the
            // two read as one pair rather than two unrelated things.
            // The VALUES are QuadX's own and never change: bookings, the
            // default-service setting and every stored order reference them.
            ['value' => 'same_day_pickup', 'label' => 'Same Day Pickup'],
            ['value' => 'next_day',        'label' => 'Next Day Pickup'],
        ];
    }

    public function getParcelOptions(): array
    {
        return [
            ['value' => 'small-pouch',  'label' => 'White Small Pouch'],
            ['value' => 'medium-pouch', 'label' => 'Blue Medium Pouch'],
            ['value' => 'big-pouch',    'label' => 'Red Large Pouch'],
            ['value' => 'box',          'label' => 'Box'],
            ['value' => 'oversized',    'label' => 'Oversized'],
        ];
    }

    /**
     * Real QuadX API capability: seller_payment_method = cash | gcash | null,
     * per their published docs (Postman collection 12850780/TVKJyvMn, "Create
     * Orders"): "available options: cash, gcash or null".
     *
     * OPEN WITH QUADX as of 2026-08-20: gcash is documented and offered in
     * their own dashboard, but POST /orders refuses it. Confirmed against both
     * sandbox and the live account, with and without seller_payment_provider
     * (which accepts gcash|quadx):
     *   {"errors":{"detail":{"seller_payment_method":
     *     ["The selected seller payment method is invalid."]}}}
     * Do not "fix" this by removing the option. It is their defect; the option
     * is correct per their contract and should start working when they fix it.
     *
     * Note their two validators when testing this. A partial payload is
     * answered by a first pass, shaped {"parameters": {...}}, which ACCEPTS
     * gcash. Only a payload complete enough to book reaches the second pass,
     * shaped {"errors":{"detail":{...}}}, which refuses it. Probing with an
     * incomplete payload reports capability that does not exist.
     *
     * Separately: a booking sent as cash is displayed by their dashboard as
     * "Paid via GCash, Scan QR" while GET /orders still returns 'cash', so
     * their pickup-collection label does not track this field either.
     * The API does NOT distinguish GCash "Scan QR" vs "Wallet" (both are just
     * gcash) — that split exists only in QuadX's own dashboard. "Receiver pays
     * the fee" (COD shipping on top of item value) is a separate mechanism
     * (a shipping line item added to the total) and isn't offered here yet.
     */
    /**
     * What a booking chooses when the caller chose nothing: the store's own
     * default service and parcel (the same settings serviceFor() and
     * parcelFor() fall back to), and cash on pickup for who pays, which is
     * how the shop actually settles with the rider. The ERP's booking form
     * opens on these; its serviceability call reads them from here.
     *
     * @return array{service: string, parcel: string, shipping_payment: string}
     */
    public function bookingDefaults(): array
    {
        return [
            'service' => $this->serviceFor([]),
            'parcel' => $this->parcelFor([]),
            'shipping_payment' => 'cash',
        ];
    }

    public function getShippingPaymentOptions(): array
    {
        return [
            ['value' => 'cash',    'label' => "I'll shoulder it: Cash on pickup"],
            ['value' => 'gcash',   'label' => "I'll shoulder it: GCash at pickup"],
            ['value' => 'net_off', 'label' => "I'll shoulder it: Deduct from my remittance / bill me"],
        ];
    }

    public function createShipment(CourierOrder $order, array $opts): array
    {
        if (PickupOrigin::from($opts) === null) {
            return ['success' => false, 'error' => 'Choose a pickup address.'];
        }

        if (trim((string) $order->reference) === '') {
            return ['success' => false, 'error' => 'The shipment has no courier reference.'];
        }

        if (($problem = $order->paymentProblem($opts)) !== null) {
            return ['success' => false, 'error' => $problem];
        }

        $payload = $this->buildPayload($order, $opts);

        $res = $this->client->createOrder($payload);
        if (!$res['ok']) {
            // A transport error or a 5xx answer to the create may still have booked the parcel: the outcome is
            // unknown and the caller must not treat it as a refusal (which would invite a second booking).
            $unknown = ($res['transport'] ?? false) === true || (int) ($res['status'] ?? 0) >= 500;

            return array_filter([
                'success' => false,
                'error' => $res['error'] ?? 'QuadX booking failed.',
                'outcome_unknown' => $unknown ?: null,
            ], fn ($v) => $v !== null);
        }

        $b = $res['body'];
        return [
            'success'         => true,
            'tracking_number' => $b['tracking_number'] ?? null,
            'status'          => $b['status'] ?? 'for_pickup',
            'meta'            => [
                // Which environment this parcel lives in, recorded at booking
                // because the setting can change afterwards and the parcel
                // cannot: a sandbox booking stays a sandbox booking.
                'mode'             => $this->client->mode(),
                'quadx_id'         => $b['id'] ?? null,
                'service'          => $opts['service'] ?? null,
                'parcel'           => $opts['parcel'] ?? null,
                'shipping_payment' => $opts['shipping_payment'] ?? 'net_off',
                'reference_id'     => $b['reference_id'] ?? (string) $order->reference,
                'booked_response'  => $b,
            ],
        ];
    }

    public function cancelShipment(string $trackingNumber): array
    {
        $res = $this->client->cancelOrder($trackingNumber);

        if ($res['ok']) {
            return ['success' => true];
        }

        return ['success' => false, 'error' => self::explainCancelFailure($res)];
    }

    /**
     * A refused cancel, in words the person holding the parcel can act on.
     *
     * QuadX's own message is always kept: it is the courier's account of what
     * happened and guessing over it would be worse than saying nothing. What
     * is added is what the operator should DO, because "HTTP 422" tells them
     * nothing about whether to try again, ring the courier, or let the parcel
     * run and refuse it on delivery.
     *
     * The mapping is by status code plus a phrase match, deliberately loose:
     * a courier is free to reword its errors, and an unrecognised one must
     * still arrive intact rather than be flattened into a generic line.
     *
     * @param array{ok:bool,status:int,body:array,error:?string} $res
     */
    private static function explainCancelFailure(array $res): string
    {
        $said = trim((string) ($res['error'] ?? '')) ?: 'QuadX gave no reason.';
        $status = (int) ($res['status'] ?? 0);
        $lower = mb_strtolower($said);

        $advice = match (true) {
            // The network never got there. Nothing was cancelled, and trying
            // again is safe.
            $status === 0 => 'QuadX could not be reached, so nothing was cancelled. Try again in a moment.',

            $status === 401 || $status === 403 => 'QuadX rejected our credentials. Check the API key and secret under Fulfillment settings.',

            $status === 404 => 'QuadX does not recognise this tracking number. It may already have been cancelled or removed on their side.',

            // Before the already-cancelled match: "cannot be cancelled, already picked up" says both words.
            str_contains($lower, 'picked up') || str_contains($lower, 'in transit') || str_contains($lower, 'out for delivery') =>
                'The parcel is already moving, so QuadX will not cancel it. Contact QuadX to recall it, or let it run and refuse the delivery.',

            str_contains($lower, 'already') && str_contains($lower, 'cancel') => 'It is already cancelled at QuadX. Refresh from couriers to bring this order back in line.',

            $status === 409 || $status === 422 =>
                'QuadX refused to cancel this parcel in its current state. This usually means it has already been picked up. Check the parcel in the QuadX dashboard.',

            $status >= 500 => 'QuadX had a server error, so nothing was cancelled. Try again shortly.',

            default => 'Check the parcel in the QuadX dashboard before trying again.',
        };

        return $said . ' ' . $advice;
    }

    /**
     * QuadX's createShipment() is synchronous (POST /orders returns the
     * tracking number directly) — it never produces courier_status='creating',
     * so there is never anything for the poll command's pending-creation pass
     * to resolve here. Sanctioned interface evolution #1's QuadX-safe default.
     */
    public function resolvePendingCreation(CourierOrder $order): ?array
    {
        return null;
    }

    public function getTracking(string $trackingNumber): array
    {
        $res = $this->client->getOrder($trackingNumber);
        if (!$res['ok']) {
            return ['success' => false, 'error' => $res['error'] ?? 'Tracking lookup failed.'];
        }
        $b = $res['body'];
        return [
            'success'  => true,
            'status'   => $b['status'] ?? null,
            'raw'      => $b,
            'links'    => (isset($b['tracking_links']) && is_array($b['tracking_links'])) ? $b['tracking_links'] : null,
            'timeline' => self::timelineFromTat($b['tat'] ?? null),
        ];
    }

    public function terminalStatuses(): array
    {
        // Final states — the poll stops re-checking once an order reaches one.
        // failed_delivery is NOT terminal (rider retries), so we keep polling it.
        return ['delivered', 'canceled', 'cancelled', 'returned', 'failed_return'];
    }

    public function cancelableStatuses(): array
    {
        return ['pending', 'confirmed', 'for_pickup', 'searching_for_rider',
                'failed_pickup_attempt', 'for_acceptance', 'for_consolidation',
                'for_dropoff', 'for_payment', 'paid'];
    }

    /** Normalize QuadX's tat map into a chronological event list. @return array<int,array{status:string,at:int}> */
    public static function timelineFromTat(?array $tat): array
    {
        if (empty($tat)) {
            return [];
        }
        $excluded = ['sla_start', 'first_pickup_at', 'first_estimated_delivery_date'];
        $events = [];
        foreach ($tat as $status => $at) {
            if (in_array($status, $excluded, true)) {
                continue;
            }
            if (!is_int($at)) {
                continue;
            }
            $events[] = ['status' => (string) $status, 'at' => $at];
        }
        usort($events, fn ($a, $b) => $a['at'] <=> $b['at']);
        return array_values($events);
    }

    /**
     * Re-bucketed onto the common 8-phase spine (see
     * Courier::statusPhase()'s docblock) while keeping
     * the pre-spine legacy 'step'/'failed' derivation byte-for-byte
     * unchanged, so today's queue view stepper (which still reads only
     * those two keys) is provably unaffected by this change.
     */
    public function statusPhase(?string $status): array
    {
        $s = (string) $status;
        [$step, $failed] = self::legacyStepAndFailed($s);

        return [
            'phase'  => self::phaseBucket($s),
            'step'   => $step,
            'failed' => $failed,
        ];
    }

    /**
     * UNCHANGED from before the 8-phase spine existed — the exact logic the
     * queue view's stepper has always rendered off. Kept as its own method,
     * untouched, rather than derived from the new phase bucket, because a
     * few raw statuses (e.g. 'failed_pickup' vs 'failed_delivery') land in
     * the SAME new bucket ('failed') but resolved to DIFFERENT legacy steps
     * depending on where in the journey they broke — information the new
     * bucket alone no longer carries.
     *
     * @return array{0:int, 1:bool}
     */
    private static function legacyStepAndFailed(string $s): array
    {
        $failed = ['failed_pickup','failed_pickup_attempt','failed_delivery','for_reattempt',
                   'returned','return_in_transit','out_for_return','for_return','canceled','cancelled',
                   'rejected','lost','damaged','disposed'];
        if (in_array($s, $failed, true)) {
            // Keep the node where it broke: pickup failures at step 1, else step 2.
            $atPickup = str_contains($s, 'pickup') || $s === 'canceled' || $s === 'cancelled' || $s === 'rejected';
            return [$atPickup ? 1 : 2, true];
        }

        if ($s === 'delivered') {
            return [3, false];
        }

        if (in_array($s, self::SHIPPED_STATUSES, true)) {
            return [2, false];
        }

        // Everything else that means "booked, awaiting pickup" -> step 1.
        return [1, false];
    }

    /** Raw statuses meaning "the courier has it" — shared by the legacy step derivation and the new spine bucketing below. */
    private const SHIPPED_STATUSES = ['picked_up','in_transit','out_for_delivery','for_delivery',
                'in_transit_to_delivery_area','received_at_delivery_hub',
                'received_at_delivery_area','at_sorting_center','at_3pl_facility',
                'received_at_pickup_hub','for_transfer','claimed','confirmed_transit'];

    /**
     * Buckets a raw QuadX status onto the spine. Never returns
     * 'to_pack' (no booking isn't a courier status) or 'booking' (QuadX's
     * createShipment() is synchronous — see the interface docblock).
     */
    private static function phaseBucket(string $s): string
    {
        if ($s === 'delivered') {
            return 'delivered';
        }

        if (in_array($s, ['canceled', 'cancelled'], true)) {
            return 'cancelled';
        }

        if (in_array($s, ['returned', 'return_in_transit', 'out_for_return', 'for_return'], true)) {
            return 'returning';
        }

        if (in_array($s, ['failed_pickup', 'failed_pickup_attempt', 'failed_delivery', 'for_reattempt',
                'rejected', 'lost', 'damaged', 'disposed', 'failed_return'], true)) {
            return 'failed';
        }

        if (in_array($s, self::SHIPPED_STATUSES, true)) {
            return 'in_transit';
        }

        // Everything else (empty/pending/confirmed/for_pickup/searching_for_rider/
        // for_acceptance/for_consolidation/for_dropoff/for_payment/paid/...) means
        // "booked, awaiting courier collection/drop-off".
        return 'to_handover';
    }

    /**
     * QuadX DOES quote, through the same endpoint that answers whether it
     * will carry the parcel at all: POST /orders/estimates/rates returns a
     * fee per parcel category for a given origin/destination pair. This
     * method used to return null because that endpoint had not been mapped;
     * the figure below is QuadX's own, for the category the operator has
     * actually selected, and nothing is ever derived from a local rate table
     * (inventing a figure QuadX never quoted would be a lie on a screen the
     * operator commits money from).
     *
     * Null still means exactly what it always did: no figure to show. That
     * covers an unreachable API, an unserviceable address, and a category
     * QuadX did not price.
     */
    public function estimateFee(CourierOrder $order, array $options): ?object
    {
        $quote = $this->rateQuote($order, $options);
        if (!$quote['ok']) {
            return null;
        }

        $parcel = (string) ($options['parcel'] ?? '');
        $amount = $quote['fees'][$parcel] ?? null;
        if (!is_numeric($amount)) {
            return null;
        }

        // `amount` and `label` keep meaning exactly what they always meant —
        // the shipping fee for the chosen parcel — because the ERP's own
        // estimate endpoint reads them and a silently widened figure would
        // change numbers it stores. Everything the total needs is ADDED
        // beside them, so both callers get what they ask for.
        $shipping = (float) $amount;
        $insurance = (float) $quote['insurance'];

        return (object) [
            'amount'    => $shipping,
            'currency'  => 'PHP',
            'label'     => '₱' . number_format($shipping, 2),
            'shipping'  => $shipping,
            'insurance' => $insurance,
            // What the operator actually settles: handed to the rider on
            // cash/gcash terms, deducted by QuadX on net-off.
            'total'     => $shipping + $insurance,
            // Every parcel priced for THIS origin, not just the chosen one.
            // The modal opened with a map quoted from the store's default
            // pickup address; if the operator has since chosen another, the
            // parcel labels must follow the total rather than contradict it.
            'fees'      => $quote['fees'],
        ];
    }

    /**
     * QuadX publishes coverage through its rate endpoint: a destination it
     * serves is priced (200), one it does not is refused in words (422,
     * `errors.detail` = "Delivery address is not serviceable.", verified
     * live against staging with Caluya, Antique).
     *
     * The refusal branch is deliberately narrow. Only a 4xx whose message
     * actually SAYS serviceable is read as a refusal. Every other failure,
     * a timeout, a 500, an auth error, a validation complaint about a postal
     * code, resolves to `unknown`, because those are all "we could not ask",
     * and telling an operator that QuadX refuses an address it never
     * evaluated is the one outcome this feature exists to prevent.
     *
     * QuadX answers everything address-shaped with the SAME sentence, for a
     * real uncovered municipality and for one that does not exist, so this
     * never tries to tell those apart: one recognised kind (destination), and
     * anything else it says is carried through in its own words.
     */
    public function checkServiceability(CourierOrder $order, PickupPoint $from): ServiceabilityVerdict
    {
        if (!$this->isEnabled()) {
            return ServiceabilityVerdict::unknown(ServiceabilityVerdict::KIND_UNREACHABLE);
        }

        $quote = $this->rateQuote($order, ['pickup' => $from], ServiceabilityService::TIMEOUT_SECONDS);

        if ($quote['ok']) {
            return ServiceabilityVerdict::serviceable($quote['fees'], 'PHP');
        }

        $said = (string) ($quote['error'] ?? '');
        $status = (int) $quote['status'];

        // Status 0 is QuadXClient's transport failure: no response at all, so
        // `$said` is curl's message and not QuadX's. It is never shown.
        if ($status === 0) {
            return ServiceabilityVerdict::unknown(ServiceabilityVerdict::KIND_UNREACHABLE);
        }

        if ($status >= 500) {
            return ServiceabilityVerdict::unknown(ServiceabilityVerdict::KIND_THEIR_SIDE);
        }

        if ($status >= 400 && stripos($said, 'serviceable') !== false) {
            return ServiceabilityVerdict::notServiceable(ServiceabilityVerdict::KIND_DESTINATION, $said);
        }

        // Something QuadX refused that we do not recognise. We have no
        // sentence of our own worth writing, so its words are carried
        // through, and the card stays clickable because we do not know that
        // this was about the address at all.
        return ServiceabilityVerdict::unknown(null, $said !== '' ? $said : null);
    }

    /**
     * One call behind both estimateFee() and checkServiceability(), so the
     * fee an operator is shown and the coverage answer they are shown can
     * never disagree.
     *
     * The origin is the SAME pickup point the booking leaves from
     * (PickupOrigin::from()), and the destination is built by the same
     * normalizer the booking payload uses, so a quote describes the shipment
     * that would actually be created.
     *
     * @return array{ok:bool, status:int, fees:array<string,float>, error:?string}
     */
    private function rateQuote(CourierOrder $order, array $opts = [], ?int $timeout = null): array
    {
        $service = $this->serviceFor($opts);

        $res = $this->client->estimateRates([
            'service_type'     => $service,
            'pickup_address'   => self::rateAddress($this->pickupAddress($opts)),
            'delivery_address' => self::rateAddress($this->deliveryAddress($order)),
            // Sent on EVERY quote, exactly as buildPayload() sends it, because
            // it is the only way QuadX will price insurance at all (an absent
            // key yields no insurance_fee in the response) and because
            // omitting it makes QuadX default it to the order total, which
            // would quote an insured shipment for an operator who did not ask
            // for one. 0 is the uninsured declaration, not a missing one.
            'declared_value'   => self::declaredValue($order, $opts),
        ], $timeout);

        if (!$res['ok']) {
            return ['ok' => false, 'status' => (int) $res['status'], 'fees' => [], 'insurance' => 0.0, 'error' => $res['error'] ?? null];
        }

        $attributes = $res['body']['data']['attributes'] ?? [];
        $fees = self::netFees($attributes);
        $insurance = $attributes['insurance_fee'] ?? null;

        return [
            'ok'        => true,
            'status'    => (int) $res['status'],
            'fees'      => $fees,
            // Absent means QuadX did not price insurance for this quote, which
            // is not the same as pricing it at zero, but both leave the
            // operator with nothing to add, and a figure we invented here
            // would be a number QuadX never quoted.
            'insurance' => is_numeric($insurance) ? (float) $insurance : 0.0,
            'error'     => null,
        ];
    }

    /**
     * What QuadX will actually bill, which is NOT the first fee map in the
     * response.
     *
     * The rate endpoint answers the fee question twice: `shipping_fee` is the
     * list price, and `fees_adjustments.shipping_fee` is the same map after
     * QuadX's own surcharges and campaigns have been applied (verified on
     * staging 2026-08-19: a box to Cebu quotes 490 list against 450 net, from
     * a fixed fuel surcharge and a percentage price drop). Billing follows the
     * net map, so every figure Venta puts on screen follows it too.
     *
     * The list map is the fallback rather than the default: a store with no
     * adjustments in force gets no `fees_adjustments` block at all, and there
     * the one map it was sent IS the net one.
     *
     * @return array<string, float|int>
     */
    private static function netFees(array $attributes): array
    {
        $adjusted = $attributes['fees_adjustments']['shipping_fee'] ?? null;
        $fees = is_array($adjusted) && $adjusted !== [] ? $adjusted : ($attributes['shipping_fee'] ?? []);

        return is_array($fees) ? array_filter($fees, 'is_numeric') : [];
    }

    /**
     * The service and parcel for this shipment, in one place because a quote
     * that priced a different service than the booking would be quoting a
     * different shipment. Both rateQuote() and buildPayload() read them here.
     *
     * The operator's configured default is the fallback, NOT a hardcoded
     * literal. buildPayload() used to fall back to 'next_day' outright, so a
     * store that had chosen Same Day Pickup was quoted on its own choice and
     * then booked on next_day regardless.
     */
    private function serviceFor(array $opts): string
    {
        return (string) ($opts['service'] ?? '')
            ?: (string) $this->settings->read('quadx_default_service', 'next_day');
    }

    private function parcelFor(array $opts): string
    {
        return (string) ($opts['parcel'] ?? '')
            ?: (string) $this->settings->read('quadx_default_parcel', 'box');
    }

    /**
     * The insured value for this shipment, in one place because a quote that
     * declared a different amount than the booking would be quoting a
     * different shipment. Both rateQuote() and buildPayload() read it here.
     */
    private static function declaredValue(CourierOrder $order, array $opts): float
    {
        return (bool) ($opts['insure'] ?? false) ? $order->insuredValue() : 0.0;
    }

    /**
     * The rate endpoint takes the address alone (no contact block), so the
     * booking payload's name/mobile_number are dropped here rather than sent
     * to an endpoint that has no use for them. `line_2` is carried as an
     * empty string because Venta stopped storing a second address line
     * (2026-03-06 migration) and the field is optional to QuadX.
     */
    private static function rateAddress(array $address): array
    {
        return [
            'line_1'      => $address['line_1'] ?? '-',
            'line_2'      => '',
            'district'    => $address['district'] ?? null,
            'city'        => $address['city'] ?? null,
            'state'       => $address['state'] ?? null,
            'postal_code' => $address['postal_code'] ?? '-',
            'country'     => $address['country'] ?? 'PH',
        ];
    }

    /**
     * QuadX schedules pickup on its own side after booking (no slot choice in
     * its API), so the booking form renders no slot picker — the interface's
     * sanctioned empty default.
     */
    public function getPickupSlots(CourierOrder $order): array
    {
        return [];
    }

    public function printLabel(string $trackingNumber, string $format): array
    {
        $type = in_array($format, ['zebra', 'fuji'], true) ? $format : 'zebra';
        $res = $this->client->printAwb($trackingNumber, $type);
        if (!$res['ok']) {
            return ['success' => false, 'error' => $res['error'] ?? 'AWB print failed.'];
        }
        $msg = $res['body']['message'] ?? [];
        return [
            'success'      => true,
            'url'          => $msg['awb'] ?? null,
            'manifest_url' => $msg['v35'] ?? null,
        ];
    }

    /**
     * The per-account keys: the four mode-scoped credential pairs and
     * quadx_default_service/parcel. On/off is the account's status and the
     * environment is the account's mode (courier_accounts columns), so
     * neither is a setting.
     */
    public function settingsSchema(): array
    {
        return [
            [
                'key'         => 'quadx_staging_api_key',
                'label'       => 'API Key',
                'type'        => 'password',
                'encrypted'   => true,
                'mode_scoped' => 'staging',
            ],
            [
                'key'         => 'quadx_staging_secret_key',
                'label'       => 'Secret Key',
                'type'        => 'password',
                'encrypted'   => true,
                'mode_scoped' => 'staging',
            ],
            [
                'key'         => 'quadx_production_api_key',
                'label'       => 'API Key',
                'type'        => 'password',
                'encrypted'   => true,
                'mode_scoped' => 'production',
            ],
            [
                'key'         => 'quadx_production_secret_key',
                'label'       => 'Secret Key',
                'type'        => 'password',
                'encrypted'   => true,
                'mode_scoped' => 'production',
            ],
            [
                'key'     => 'quadx_default_service',
                'label'   => 'Default schedule',
                'type'    => 'select',
                'options' => $this->getServiceTypes(),
                // Not "pre-selected on the booking form" any more: since
                // 2026-09-03 that form asks every time and pre-selects
                // nothing. This is the fallback for a booking that arrives
                // without a schedule, which is the ERP API's path.
                'help'    => 'Used when a booking arrives without one, such as from the ERP API. The booking form asks every time.',
            ],
            [
                'key'     => 'quadx_default_parcel',
                'label'   => 'Default parcel',
                'type'    => 'select',
                'options' => $this->getParcelOptions(),
                'help'    => 'Used when a booking arrives without one, such as from the ERP API. The booking form asks every time.',
            ],
        ];
    }

    /**
     * GoGo Xpress is QuadX's consumer brand and runs a public tracker keyed
     * by the tracking number alone, one host per environment to match the
     * API split (api.staging.quadx.xyz / api.quadx.xyz). The API's own
     * tracking_links are preferred wherever they exist; this is what the
     * shopper gets when the API hands back none, which a live refresh of a
     * booked parcel showed it does.
     */
    public function publicTrackingUrl(string $trackingNumber, array $courierMeta): ?string
    {
        $trackingNumber = trim($trackingNumber);
        if ($trackingNumber === '') {
            return null;
        }

        // The booking's own environment. A parcel booked before the mode was
        // recorded has no better witness than the mode configured now, which
        // is right for every store that never switched; one that booked in
        // the sandbox and then went live will link those old sandbox parcels
        // to the live tracker, and that is the limit of what can be known.
        $mode = $courierMeta['mode'] ?? $this->client->mode();

        $host = $mode === 'production' ? 'https://app.gogoxpress.com' : 'https://app.staging.gogoxpress.com';

        return $host . '/track/' . rawurlencode($trackingNumber);
    }

    /**
     * What QuadX charged the store for this shipment.
     *
     * The booking response answers twice, the same way the rate endpoint does
     * (see feesFrom()): `shipping_fee` is the list price and
     * `fees_adjustment.shipping_fee` is the same figure after QuadX's own
     * campaigns and surcharges. A real booking came back 80.00 list against
     * 77.00 adjusted, being a 10% price drop and a 5.00 fuel surcharge. The
     * store pays the adjusted one, so that is what is recorded.
     *
     * Null, never zero, when QuadX said nothing: an absent figure means the
     * cost is not known, and reporting it as free would be a lie the margin
     * is then computed from.
     */
    public function actualShippingCost(array $courierMeta): ?float
    {
        $booked = $courierMeta['booked_response'] ?? null;

        if (! is_array($booked)) {
            return null;
        }

        $fee = $booked['fees_adjustment']['shipping_fee'] ?? $booked['shipping_fee'] ?? null;

        return is_numeric($fee) ? (float) $fee : null;
    }

    public function parseWebhook(array $payload): array
    {
        $events = [];
        foreach (($payload['data'] ?? []) as $e) {
            $events[] = [
                'reference_id'    => $e['reference_id'] ?? null,
                'tracking_number' => $e['tracking_number'] ?? null,
                'status'          => $e['status'] ?? 'unknown',
                'occurred_at'     => $e['status_updated_at'] ?? null,
                'remarks'         => $e['remarks'] ?? null,
            ];
        }
        return $events;
    }

    /**
     * The GoGo Xpress tracker, and nothing else.
     *
     * Owner ruling 2026-09-03: app.gogoxpress.com/track/{tracking} only.
     *
     * QuadX's API hands back tracking_links pointing at fareye.co, the
     * logistics platform GoGo Xpress runs on rather than the brand a shopper
     * bought from. Those were preferred here and in resolveTrackingLink(),
     * so a live parcel showed a fareye link in the queue while the customer
     * held a gogoxpress one from an earlier write. Both now build the same
     * URL from the tracking number, so the queue and the customer's email
     * cannot disagree, and no link the shopper does not recognise is shown.
     *
     * The environment still comes from the booking's own mode, so a sandbox
     * parcel keeps pointing at the sandbox tracker.
     */
    public function trackingLinks(CourierOrder $order): array
    {
        $tracking = trim((string) $order->courier_tracking_number);
        if ($tracking === '') {
            return [];
        }

        $meta = $order->courier_meta ? (json_decode($order->courier_meta, true) ?: []) : [];
        $url = $this->publicTrackingUrl($tracking, is_array($meta) ? $meta : []);

        return $url ? [['label' => 'Track', 'url' => $url]] : [];
    }

    public function testConnection(): array
    {
        if (!$this->client->isConfigured()) {
            return ['ok' => false, 'message' => 'API key/secret not set.'];
        }
        $res = $this->client->getStatuses();
        return $res['ok']
            ? ['ok' => true, 'message' => 'Connected. QuadX reachable and credentials valid.']
            : ['ok' => false, 'message' => 'Failed: ' . ($res['error'] ?? 'unknown (HTTP ' . $res['status'] . ')')];
    }

    public function refreshStatusLabels(): array
    {
        if (!$this->client->isConfigured()) {
            return ['ok' => false, 'error' => 'API key/secret not set. Cannot refresh statuses.'];
        }

        $res = $this->client->getStatuses();
        if (!$res['ok']) {
            return ['ok' => false, 'error' => 'Refresh failed: ' . ($res['error'] ?? 'unknown error')];
        }

        $labels = [];
        foreach ($res['body'] as $s) {
            if (!empty($s['name'])) {
                $labels[$s['name']] = $s['label'] ?? ucwords(str_replace('_', ' ', $s['name']));
            }
        }

        return ['ok' => true, 'labels' => $labels];
    }

    /** Curated subset presented on the status-mapping table — unchanged from before this became courier-owned data. */
    /**
     * The statuses an order status may be hung on, cherry-picked by the owner
     * (2026-08-30) from the 59 QuadX's own /orders/statuses endpoint lists.
     * The nine this used to be were a guess that never included half of
     * what QuadX actually sends (received_at_pickup_hub stranded a live
     * order; failed_pickup_attempt is what arrives, never failed_pickup).
     *
     * Left out on purpose: for_release and claimed (never seen), the
     * claims/lost/damaged/disposal chain (waits on the returns process,
     * board VENT-18), and the four ops-system events (dws_update,
     * runsheet_closure, rcd_*). The map page appends any status this store
     * has actually received that is not listed here, so nothing can strand
     * an order silently again.
     */
    public function mappableStatuses(): array
    {
        return array_merge(...array_values($this->mappableStatusGroups()));
    }

    /**
     * The same statuses, in the order and under the headings a merchant
     * reads the parcel's journey in. Group label => raw statuses.
     *
     * @return array<string, list<string>>
     */
    public function mappableStatusGroups(): array
    {
        return [
            'Before pickup'      => ['for_pickup', 'accepted', 'pickup_rider_found', 'out_for_pickup'],
            'Picked up, moving'  => ['picked_up', 'received_at_pickup_hub', 'for_transfer', 'at_sorting_center', 'at_3pl_facility',
                                     'in_transit', 'in_transit_to_delivery_area', 'received_at_delivery_hub',
                                     'received_at_delivery_area', 'for_delivery', 'delivery_rider_found', 'out_for_delivery'],
            'Delivered'          => ['delivered'],
            'Failed attempt'     => ['failed_pickup_attempt', 'failed_pickup', 'failed_delivery', 'for_reattempt', 'rejected'],
            'Return to sender'   => ['for_return', 'out_for_return', 'return_in_transit', 'arrived_at_rts_hub',
                                     'arrived_at_rts_area', 'at_sorting_center_rts', 'returned', 'failed_return'],
            'Cancelled'          => ['canceled'],
        ];
    }

    /**
     * Which Venta status a row starts on before the merchant has chosen:
     * the module's own booked status for everything still at the pickup
     * end, Shipped once the courier has it, and the obvious two ends.
     * Names, resolved to ids by the page; a store without the name gets a
     * blank row, never a wrong one.
     *
     * @return array<string, string> raw status => Venta status NAME, or the
     *   token '@booked' for "whatever status is placed under To Handover"
     */
    public function suggestedStatusMap(): array
    {
        $groups = $this->mappableStatusGroups();
        $map = [];
        foreach ($groups['Before pickup'] as $k) { $map[$k] = '@booked'; }
        foreach ($groups['Picked up, moving'] as $k) { $map[$k] = 'Shipped'; }
        $map['delivered'] = 'Delivered';
        $map['canceled'] = 'Canceled';

        return $map;
    }

    // --- Payload builder --------------------------------------------------

    private function buildPayload(CourierOrder $order, array $opts): array
    {
        // The shipment's payment method decides; createShipment() refuses a buyer_payment that contradicts it.
        $buyerPayment = $this->orderIsCod($order) ? 'cod' : 'prepaid';

        $payload = [
            'status'           => 'for_pickup',
            'service'          => $this->serviceFor($opts),
            'reference_id'     => (string) $order->reference,
            'delivery_address' => $this->deliveryAddress($order),
            'pickup_address'   => $this->pickupAddress($opts),
            'contact_number'   => $order->shipping_phone ?: ($order->phone ?: ''),
            'buyer_name'       => trim($order->shipping_first_name . ' ' . $order->shipping_last_name),
            // QuadX prices by parcel category (shipment), not by weight/dimensions —
            // it accepts orders with no parcel object, so we don't collect or send them.
            'payment_provider' => 'other',
            'payment_method'   => $buyerPayment === 'cod' ? 'cod' : 'other',
            'items'            => $this->items($order),
            'currency'         => 'PHP',
            'shipment'         => $this->parcelFor($opts),
            // On COD, `total` is what QuadX's rider collects: the shipment's cod_amount, never its order total.
            'total'            => $buyerPayment === 'cod' ? (float) $order->collectAmount() : (float) $order->total,
            // QuadX insures via declared_value (~1% fee on that amount). Omitting
            // this field entirely makes QuadX silently DEFAULT declared_value to
            // the order total — auto-insuring every booking with an unwanted fee.
            // We always send it explicitly: 0 = uninsured (default), order total
            // = insured (admin opt-in via the "Insure shipment" checkbox).
            'declared_value'   => self::declaredValue($order, $opts),
            'async_address_validate' => false,
        ];

        // Seller's shipping-fee payment. Omit entirely for net-off (deduct/bill).
        $shippingPayment = $opts['shipping_payment'] ?? 'net_off';
        if (in_array($shippingPayment, ['cash', 'gcash'], true)) {
            $payload['seller_payment_method'] = $shippingPayment;
        }

        return $payload;
    }

    private function deliveryAddress(CourierOrder $order): array
    {
        $address = [
            'name'          => trim($order->shipping_first_name . ' ' . $order->shipping_last_name) ?: 'Customer',
            'mobile_number' => $order->shipping_phone ?: ($order->phone ?: ''),
            'line_1'        => $order->shipping_address_1 ?: '-',
            'district'      => $order->shipping_district ?: ($order->shipping_barangay ?: null),
            'city'          => $this->resolver->normalizeCity($order->shipping_city, $order->shipping_state),
            'state'         => $this->resolver->normalizeState($order->shipping_state),
            'postal_code'   => $order->shipping_postcode ?: '-',
            'country'       => 'PH',
        ];

        // The buyer's message to the courier (core's courier_comment),
        // surfaced on the QuadX dashboard as the
        // address block's "Other locations and details" field (verified on
        // staging 2026-08-16: delivery_address.remarks is accepted, persisted
        // and returned by GET /orders; notes/instructions/landmark are
        // silently dropped). Omitted entirely when there is no note.
        $remarks = trim((string) $order->courier_comment);
        if ($remarks !== '') {
            $address['remarks'] = mb_substr($remarks, 0, 500);
        }

        return $address;
    }

    /**
     * The origin the client sent with the shipment ($opts['pickup'], see
     * PickupOrigin::from()).
     *
     * QuadX's `district` carries the barangay (its own delivery block does
     * the same, see deliveryAddress()), preferring an explicit district when
     * there is one, which is exactly what deliveryAddress() does with
     * shipping_district.
     */
    private function pickupAddress(array $opts = []): array
    {
        $pickup = PickupOrigin::from($opts);

        return [
            'name'          => ($pickup?->contact_name ?: 'Sender'),
            'mobile_number' => $pickup?->contact_phone ?: '',
            'line_1'        => $pickup?->address_1 ?: '-',
            'district'      => ($pickup?->district ?: ($pickup?->barangay ?: null)),
            'city'          => $this->resolver->normalizeCity($pickup?->city, $pickup?->state),
            'state'         => $this->resolver->normalizeState($pickup?->state),
            'postal_code'   => $pickup?->postcode ?: '-',
            'country'       => PickupOrigin::COUNTRY,
        ];
    }

    private function items(CourierOrder $order): array
    {
        $items = [];
        foreach ($order->products as $p) {
            $items[] = [
                'type'        => 'product',
                'description' => $p->name ?: 'Item',
                'amount'      => (float) $p->price,
                'quantity'    => (int) $p->quantity,
            ];
        }
        if ($items === []) {
            $items[] = ['type' => 'product', 'description' => 'Order ' . $order->reference, 'amount' => (float) $order->total, 'quantity' => 1];
        }
        return $items;
    }

    private function orderIsCod(CourierOrder $order): bool
    {
        return (bool) $order->cod;
    }
}
