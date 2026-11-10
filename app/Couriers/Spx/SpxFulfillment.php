<?php

namespace App\Couriers\Spx;

use App\Couriers\AccountSettings;
use App\Couriers\CourierOrder;
use App\Couriers\Contracts\Courier;
use App\Couriers\Contracts\ProvidesPublicTrackingUrl;
use App\Couriers\PickupOrigin;
use App\Couriers\PickupPoint;
use App\Couriers\ServiceabilityService;
use App\Support\PhAddress;
use App\Couriers\Support\OrderWeight;
use App\Couriers\Support\PhPhone;
use App\Couriers\Support\ServiceabilityVerdict;
use App\Models\CourierAccount;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * SPX (Shopee Xpress) courier: createShipment/cancelShipment/getTracking,
 * the status-vocabulary/phase methods, the option lists, and the
 * estimateFee()/getPickupSlots() methods. Ground truth for every shape is
 * docs/couriers/spx/api.md (verbatim endpoint tables) — see each method's
 * docblock for which section it implements.
 *
 * Labels and webhooks: printLabel() (1.6 Get AWB V2 — download+store the
 * perishable label PDF, serve it through this app's own authenticated
 * route), parseWebhook() + verifyWebhookSignature() (4.1 Tracking Web Hook —
 * signed fast-ack dispatch, see CourierWebhookController's SPX branch and
 * App\Couriers\Jobs\ProcessCourierWebhookJob).
 *
 * Load-bearing decisions:
 *  - Sender address reuses the module-wide pickup origin rather than a new
 *    spx_sender_* settings block, since QuadX already shares that exact
 *    origin — one pickup address serves every courier. That origin is a
 *    row in courier_fulfillment_pickup_addresses (a store may keep several
 *    and choose one per booking).
 *  - Sender and deliver addresses are placed on SPX's own location list
 *    (SpxLocations, compiled from SPX's xlsx by ventaship:spx-locations-compile):
 *    SPX's state/city/district/street are island group/province/city/barangay,
 *    not PSGC's levels, and an address SPX does not list is refused before
 *    anything is sent. refreshAddressTree() still caches SPX's 5.1 download
 *    link for an admin to fetch a newer list.
 *  - collect_type follows the shipment's method: a drop-off is 2, a pickup
 *    is 1 with its window (the booking form's choice, or, for an API order
 *    without one, the earliest window 1.1 offers, stored on the shipment).
 *    A pickup is never booked as a drop-off.
 *  - Fees: the booking's estimated_shipping_fee (1.5) is the shipment's
 *    fee, on every booking path (createShipment()'s `fee`,
 *    resolvePendingCreation()'s `fee`, and a webhook-resolved booking, which
 *    asks 1.5 once through resolvePendingCreation()). The actual fee comes
 *    from the 4.1 webhook's actual_shipping_fee and, once the parcel is
 *    delivered or returned, from 2.2 batch_get_asf (getTracking(), at most
 *    once per parcel per day). App\Shipments\CourierFacts stores it.
 */
class SpxFulfillment implements Courier, ProvidesPublicTrackingUrl
{
    /**
     * Sensible default PH pouch dimensions (editable via the
     * spx_pouch_presets setting — see settingsSchema()). Well inside SPX's
     * documented PH parcel limits (weight < 50kg,
     * each dimension <= 150cm). Small/Medium/Large mirrors the same shopper
     * mental model QuadX's pouch parcel options already use.
     */
    public const DEFAULT_POUCH_PRESETS = [
        ['label' => 'Small',  'length_cm' => 25, 'width_cm' => 20, 'height_cm' => 10, 'max_weight_kg' => 3],
        ['label' => 'Medium', 'length_cm' => 35, 'width_cm' => 25, 'height_cm' => 15, 'max_weight_kg' => 5],
        ['label' => 'Large',  'length_cm' => 45, 'width_cm' => 35, 'height_cm' => 20, 'max_weight_kg' => 10],
    ];

    /**
     * SPX's parcel_item_type enum (api.md §1.4), verbatim. It has no
     * "unspecified" value, so a shipment without a `parcel.category` (or
     * with one SPX does not list) declares the generic catch-all.
     */
    public const PARCEL_ITEM_TYPES = [
        'Food and beverage', 'Clothing', 'Electronics', 'Medical', 'Groceries', 'General merchandise', 'Fragile',
    ];

    private const PARCEL_ITEM_TYPE = 'General merchandise';

    /** PH parcel and COD limits (docs/couriers/spx/guide.md:600-604; api.md cod_amount). */
    private const MAX_WEIGHT_KG = '50';

    private const MAX_SIDE_CM = '150';

    private const MAX_COD = '50000';

    /**
     * SPX's own "Order status code" table (docs/spx/guide.md, lines
     * 506-519, "## Order status code", Last Updated 15/04/2025). No live
     * status-vocabulary endpoint exists in the documented API surface (see
     * refreshStatusLabels()'s docblock), but this table IS the documented,
     * stable vocabulary SPX support tickets and the tracking timeline both
     * speak in, so it ships here as a constant rather than staying a
     * permanent no-op. Keys match mappableStatuses() exactly (order status
     * codes only, not the unrelated app/param/create-account/cancel
     * error-code tables elsewhere in guide.md).
     */
    public const STATUS_LABELS = [
        '1001' => 'Pending Pickup',
        '2001' => 'In Transit',
        '2006' => 'Delivering',
        '3001' => 'On Hold',
        '4001' => 'Delivered',
        '5001' => 'Pickup Failed',
        '5002' => 'Damaged',
        '5003' => 'Lost',
        '6001' => 'Returning',
        '6002' => 'Return Failed',
        '6003' => 'Returned',
        '7001' => 'Cancelled',
    ];

    /**
     * SPX's failures, classified by GROUP rather than one code at a time,
     * so a code nobody has seen yet still lands
     * somewhere sensible instead of falling through as a mystery.
     *
     * These four are REFUSALS of this shipment: the card goes inert and the
     * modal writes its own sentence for the group, naming the destination.
     * Codes are a grouping of docs/couriers/spx/guide.md's error tables.
     *
     * Two of them are deliberately NOT "does not deliver here":
     *  - `cod` is an address SPX will happily deliver to, just not collecting
     *    cash at, which matters because this store sells heavily on COD.
     *  - `postcode` is a field on the address, and naming it is what lets an
     *    operator fix it in ten seconds.
     */
    private const REFUSAL_GROUPS = [
        // Destination not covered. 10062 and 10066 are further guide.md codes,
        // added here because they say the same thing:
        // "no route code for this address", "basic serviceable area not
        // configured".
        // 10055 (delivery branch), 10067-10069 (serviceable area or route not
        // configured) and 10071/10075 (delivery address not in the basic / ops
        // serviceable area) are guide/08's, and say the same.
        ServiceabilityVerdict::KIND_DESTINATION => [10016, 10019, 10023, 10026, 10033, 10055, 10062, 10066, 10067, 10068, 10069, 10071, 10075],
        // Origin not covered. 10065 ("pickup and delivery are not supported")
        // is guide.md's, and lands here because a pickup address SPX will not
        // collect from blocks every order, not just this one. 10070/10074:
        // pickup address not in the basic / ops serviceable area.
        ServiceabilityVerdict::KIND_ORIGIN      => [10014, 10015, 10018, 10025, 10054, 10065, 10070, 10074],
        // COD not supported at this address (10017: COD delivery).
        ServiceabilityVerdict::KIND_COD         => [10017, 10029, 10030, 10031, 10032, 10043],
        // The postcode itself is the problem.
        ServiceabilityVerdict::KIND_POSTCODE    => [10027, 10028],
    ];

    /**
     * Failures that say NOTHING about whether SPX serves this address, so
     * they resolve to `unknown` and leave the card clickable:
     *
     *  - `parcel`: about the parcel or the COD amount, and the parcel is not
     *    chosen until step 2. The check quotes SPX's smallest preset, so a
     *    refusal here is a refusal of OUR guess, and the operator can pick a
     *    real parcel and quote it exactly with Get estimate.
     *  - `slot`: a pickup slot is our own form's field, not SPX's coverage.
     *    10024 ("pickup option not supported") is guide.md's, and it is about
     *    the collect_type WE sent.
     *  - `their_side`: an outage. Same bucket as a dead DNS record, for the
     *    same reason: nobody evaluated the address.
     */
    private const UNCERTAIN_GROUPS = [
        ServiceabilityVerdict::KIND_PARCEL     => [10001, 10003, 10004, 10011, 10012, 10050, 10064, 10082, 10083, 10084, 10085, 10086],
        ServiceabilityVerdict::KIND_SLOT       => [10013, 10024, 10038, 10039, 10040, 10056, 10080, 10081],
        ServiceabilityVerdict::KIND_THEIR_SIDE => [10020, 10044, 10045, 10049, 10052, 10057, 10060, 10061, 10076],
    ];

    /**
     * The `code` a verdict from SPX's own answer carries, the same vocabulary
     * as the codes our location-list check gives (docs/api.md, coverage):
     * clients read one code whichever side found it.
     *
     * 10021/10022 (too far / too near) and 10077/10078 (SPX could not place
     * an address on its map) stay `unknown`: SPX measures the distance from
     * its own geocoding of our text, so a bad geocode reads as a distance
     * refusal, and the booking itself still gets SPX's real answer.
     */
    private const VERDICT_CODES = [
        'spx_no_delivery'             => [10016, 10019, 10026, 10033, 10055, 10066, 10067, 10068, 10071, 10075],
        'spx_no_route'                => [10023, 10062, 10069],
        'spx_no_pickup'               => [10014, 10018, 10025, 10054, 10065, 10070, 10074],
        'spx_no_cod'                  => [10017, 10030, 10032],
        'spx_no_cod_pickup'           => [10015, 10029, 10031],
        'spx_invalid_pickup_postcode' => [10027],
        'spx_invalid_postcode'        => [10028],
        'spx_too_far'                 => [10021],
        'spx_too_near'                => [10022],
        'spx_unmapped_pickup'         => [10077],
        'spx_unmapped_address'        => [10078],
    ];

    private static function verdictCode(int $code): ?string
    {
        foreach (self::VERDICT_CODES as $name => $codes) {
            if (in_array($code, $codes, true)) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Which group a code belongs to, and whether that group is a refusal.
     *
     * @return array{0:?string, 1:bool} [kind, isRefusal]. A null kind is a
     *   code we do not recognise: never a refusal, and the caller carries
     *   SPX's own words through instead of inventing a sentence for it.
     */
    private static function classify(int $code): array
    {
        foreach (self::REFUSAL_GROUPS as $kind => $codes) {
            if (in_array($code, $codes, true)) {
                return [$kind, true];
            }
        }

        foreach (self::UNCERTAIN_GROUPS as $kind => $codes) {
            if (in_array($code, $codes, true)) {
                return [$kind, false];
            }
        }

        return [null, false];
    }

    /** api.md §"Order status codes", bucketed for statusPhase()/terminalStatuses()/cancelableStatuses(). */
    private const DELIVERED = ['4001'];
    private const CANCELLED = ['7001'];
    /** Coming back to the seller — in progress (6001) or completed (6003), mirrors QuadX's 'returned' also living in the 'returning' bucket, not 'delivered'. */
    private const RETURNING_STATUSES = ['6001', '6003'];
    /** Failed attempts: 5001 Pickup Failed is pre-pickup (step 1); 6002 Return Failed happens once SPX has the parcel (step 2). */
    private const FAILED_STATUSES = ['5001', '6002'];
    private const FAILED_PRE_PICKUP = ['5001'];
    /** 5002 Damaged and 5003 Lost (guide/09): final, and a COD parcel will never be paid for (PaymentState voids it). */
    private const LOST_DAMAGED_STATUSES = ['5002', '5003'];
    /** 3001 On Hold: "placed on hold during delivery" (guide/09). SPX has the parcel; not final. */
    private const ON_HOLD_STATUSES = ['3001'];
    /** Courier has the parcel and it's moving. */
    private const IN_TRANSIT_STATUSES = ['2001', '2006'];

    private SpxClient $client;

    private \Closure $sleeper;

    public function __construct(
        private readonly CourierAccount $account,
        private readonly AccountSettings $settings,
        ?SpxClient $client = null,
        ?\Closure $sleeper = null,
    ) {
        $this->client = $client ?? new SpxClient($settings);
        // Real wall-clock backoff by default; tests inject a no-op (or
        // recording) closure so bounded-polling logic never actually sleeps.
        $this->sleeper = $sleeper ?? static function (int $ms): void {
            usleep($ms * 1000);
        };
    }

    public function getKey(): string
    {
        return 'spx';
    }

    public function getName(): string
    {
        return 'SPX';
    }

    /** The account is active and holds the app-level pair (app-id + secret), mirroring QuadX's pattern. */
    public function isEnabled(): bool
    {
        return $this->account->status === 'active' && $this->client->isConfigured();
    }

    /** @see Courier::configurationIssues() */
    public function configurationIssues(): array
    {
        return $this->client->missingCredentialLabels();
    }

    /** SPX only documents one service_type (1: standard service) — see api.md §1.4. */
    public function getServiceTypes(): array
    {
        return [
            ['value' => 'standard', 'label' => 'Standard Service'],
        ];
    }

    /** Configured spx_pouch_presets (or DEFAULT_POUCH_PRESETS) plus a custom-dims escape hatch. */
    public function getParcelOptions(): array
    {
        $options = [];
        foreach ($this->pouchPresets() as $preset) {
            $label = (string) ($preset['label'] ?? 'Preset');
            $options[] = [
                'value' => Str::slug($label),
                'label' => sprintf(
                    '%s (%s×%s×%scm, up to %skg)',
                    $label,
                    $preset['length_cm'] ?? '?',
                    $preset['width_cm'] ?? '?',
                    $preset['height_cm'] ?? '?',
                    $preset['max_weight_kg'] ?? '?'
                ),
            ];
        }
        $options[] = ['value' => 'custom', 'label' => 'Custom dimensions…'];

        return $options;
    }

    /** SPX's payment_role: 1 sender pay, 2 receiver pay (api.md §1.4). */
    public function getShippingPaymentOptions(): array
    {
        return [
            ['value' => 'sender_pay',   'label' => "I'll shoulder it: Sender pays the shipping fee"],
            ['value' => 'receiver_pay', 'label' => 'Customer pays the shipping fee (Receiver pay)'],
        ];
    }

    /**
     * 1.4 Create Order V2 -> 1.5 Get Order Create Result V2 (api.md §1.4/§1.5).
     * Submits ONE order per call (Venta books orders one at a time), then
     * polls the async result bounded to ~15s (pollCreateResult()). If it
     * finishes within the window, returns the real tracking number; if not,
     * persists courier_status='creating' + batch_no in meta and returns a
     * "pending" success (see this class's docblock + resolvePendingCreation()
     * for how that gets completed later by courier:poll-status).
     */
    public function createShipment(CourierOrder $order, array $opts): array
    {
        // The booking gate must
        // consult USER-credential completeness (not just app-id/secret) and
        // fail with a clear message, never a raw SPX auth error.
        if (!$this->client->hasAccountCredentials()) {
            return ['success' => false, 'error' => 'SPX booking requires the User ID / User Secret to be configured on the account before booking.'];
        }

        if (trim((string) $order->reference) === '') {
            return ['success' => false, 'error' => 'The shipment has no courier reference.'];
        }

        if (($problem = $order->paymentProblem($opts)) !== null) {
            return ['success' => false, 'error' => $problem];
        }

        $priorMeta = self::metaOf($order);
        $changes = [];
        $pickup = self::wantsPickup($opts);
        $slot = null;
        if ($pickup) {
            // A pickup always has a window: the one asked for, else (an API order without one) the earliest SPX
            // offers. A day already over is replaced the same way. Never booked as a drop-off instead.
            $slot = self::decodeSlot($opts['pickup_slot'] ?? null);
            if ($slot === null || self::slotPassed($slot)) {
                $next = $this->nextSlot(null);
                if (!$next['ok']) {
                    return ['success' => false, 'error' => $next['error']];
                }
                $changes[] = self::slotChange($next['slot'], $slot === null ? 'chosen' : 'passed');
                $slot = $next['slot'];
            }
            $opts['pickup_slot'] = self::encodeSlot($slot);
        } else {
            unset($opts['pickup_slot']);
        }

        $built = $this->buildOrderPayload($order, $opts);
        if (!$built['ok']) {
            return ['success' => false, 'error' => $built['error']];
        }

        if (($problem = $this->limitProblem($order, $opts)) !== null) {
            return ['success' => false, 'error' => $problem];
        }

        $attempt = $this->submitOrder($built['payload']);

        if ($pickup && $attempt['kind'] === 'refused' && $attempt['code'] === 10080) {
            // Past SPX's cutoff (guide 12: "use the latest returned results"): ask 1.1 again, once, and book the
            // window SPX lists after the refused one.
            $refused = $slot;
            $next = $this->nextSlot($refused);
            if (!$next['ok']) {
                return ['success' => false, 'error' => 'The pickup time ' . self::slotLabel($refused) . " is past SPX's cutoff"
                    . ($next['error'] === self::NO_PICKUP_TIME ? ', and SPX has no later pickup time for this address.' : '. ' . $next['error'])];
            }
            $slot = $next['slot'];
            $changes[] = self::slotChange($slot, 'cutoff');
            $opts['pickup_slot'] = self::encodeSlot($slot);
            $built = $this->buildOrderPayload($order, $opts);
            if (!$built['ok']) {
                return ['success' => false, 'error' => $built['error']];
            }
            $attempt = $this->submitOrder($built['payload']);
            if ($attempt['kind'] === 'refused' && $attempt['code'] === 10080) {
                return ['success' => false, 'error' => 'SPX refused the pickup times ' . self::slotLabel($refused) . ' and ' . self::slotLabel($slot) . " as past its cutoff. Choose another pickup time. (SPX 10080)"];
            }
        }

        if ($attempt['kind'] === 'refused' && $attempt['code'] === 13103) {
            return $this->alreadyCreated($order, $priorMeta, $attempt['batch_no'] ?? null);
        }

        if ($attempt['kind'] === 'unknown') {
            return ['success' => false, 'error' => $attempt['error'], 'outcome_unknown' => true];
        }
        if ($attempt['kind'] === 'refused') {
            return ['success' => false, 'error' => $attempt['error']];
        }

        $meta = [
            'service'          => $opts['service'] ?? 'standard',
            'parcel'           => $opts['parcel'] ?? null,
            'shipping_payment' => $opts['shipping_payment'] ?? 'sender_pay',
            'reference_id'     => $built['payload']['order_id'],
            'batch_no'         => $attempt['batch_no'],
        ];
        if ($changes !== []) {
            $before = is_array($priorMeta['pickup_changes'] ?? null) ? array_values($priorMeta['pickup_changes']) : [];
            $meta['pickup_changes'] = array_slice([...$before, ...$changes], -20);
        }
        $slotValue = $pickup ? $opts['pickup_slot'] : null;

        if ($attempt['kind'] === 'booked') {
            return [
                'success'         => true,
                'tracking_number' => $attempt['order']['tracking_no'],
                // Not in the create-result response — the next tracking
                // poll (courier:poll-status, or a manual Refresh) fills
                // this in. Null here is deliberate, not a bug.
                'status'          => null,
                'meta'            => self::bookedMeta($attempt['order']) + $meta,
                'fee'             => Money::decimal($attempt['order']['estimated_shipping_fee'] ?? null),
                'pickup_slot'     => $slotValue,
            ];
        }

        // Still pending after the bounded poll window.
        $meta['pending'] = true;
        return [
            'success'         => true,
            'tracking_number' => null,
            'status'          => 'creating',
            'meta'            => $meta,
            'pickup_slot'     => $slotValue,
        ];
    }

    /**
     * One 1.4 submit and its bounded 1.5 poll.
     *
     * @return array{kind:'booked', order:array, batch_no:int|string}
     *       | array{kind:'pending', batch_no:int|string}
     *       | array{kind:'refused', error:string, code:?int, batch_no?:int|string}
     *       | array{kind:'unknown', error:string}
     */
    private function submitOrder(array $payload): array
    {
        try {
            $createData = $this->client->createOrders([$payload]);
        } catch (SpxApiException $e) {
            if ($e->httpStatus >= 500) {
                // A server error: SPX may have taken the order anyway.
                return ['kind' => 'unknown', 'error' => "SPX booking failed: {$e->getMessage()} (ret_code {$e->retCode})"];
            }

            return ['kind' => 'refused', 'error' => "SPX booking failed: {$e->getMessage()} (ret_code {$e->retCode})", 'code' => $e->retCode];
        } catch (\Throwable $e) {
            // Transport failure, timeout or a non-envelope answer (a 5xx page): the order may exist at SPX.
            return ['kind' => 'unknown', 'error' => 'SPX booking failed: ' . $e->getMessage()];
        }

        $batchNo = $createData['batch_no'] ?? null;
        if (!$batchNo) {
            // SPX accepted the request (ret_code 0) but gave nothing to follow it by.
            return ['kind' => 'unknown', 'error' => 'SPX did not return a batch number for this booking.'];
        }

        $resultData = $this->pollCreateResult((int) $batchNo);
        if ($resultData === null) {
            return ['kind' => 'pending', 'batch_no' => $batchNo];
        }

        $outcome = $this->outcomeFromCreateResult($resultData);

        return $outcome['ok']
            ? ['kind' => 'booked', 'order' => $outcome['order'], 'batch_no' => $batchNo]
            : ['kind' => 'refused', 'error' => $outcome['error'], 'code' => $outcome['code'] ?? null, 'batch_no' => $batchNo];
    }

    /**
     * SPX answered 13103: it already has an order with this order_id, from an earlier attempt whose answer never
     * arrived (a timeout, then a Retry). The order is looked up and, when found, the shipment is booked with it as
     * from a normal booking answer. When it cannot be found the outcome is unknown, never a refusal: a parcel exists
     * at SPX that Ventaship must not forget.
     *
     * @param array<string, mixed> $priorMeta the shipment's meta before this attempt
     */
    private function alreadyCreated(CourierOrder $order, array $priorMeta, int|string|null $refusedBatch): array
    {
        $ref = (string) $order->reference;
        $found = $this->existingOrder($order, $priorMeta, $refusedBatch === null ? null : (int) $refusedBatch);
        if ($found === null) {
            Log::warning('SPX already has this order_id but it could not be found', ['order' => $order->id, 'reference' => $ref]);

            return [
                'success'          => false,
                'outcome_unknown'  => true,
                'error'            => "SPX already has order {$ref}.",
                'attention_reason' => "SPX already has order {$ref}: find its tracking number in the SPX portal and record it",
            ];
        }

        return [
            'success'          => true,
            'tracking_number'  => (string) $found['order']['tracking_no'],
            'status'           => $found['status'],
            'meta'             => self::bookedMeta($found['order']) + ['reference_id' => $ref, 'batch_no' => $found['batch_no'], 'recovered' => 13103],
            'fee'              => Money::decimal($found['order']['estimated_shipping_fee'] ?? null),
            'notice'           => "SPX already had order {$ref} from an earlier attempt.",
            'attention_reason' => "SPX already had order {$ref} from an earlier attempt: check its details in the SPX portal",
        ];
    }

    /**
     * The SPX order this shipment already has: in the earlier batch (1.5) when one is on file, else by Track (1.2)
     * on the tracking number on file, else on the order_id itself. A Track answer counts only when it names this
     * order_id (or, with none, the tracking number asked for). Every lookup is best effort.
     *
     * @param array<string, mixed> $priorMeta
     * @return array{order:array<string, mixed>, batch_no:int|string|null, status:?string}|null
     */
    private function existingOrder(CourierOrder $order, array $priorMeta, ?int $refusedBatch): ?array
    {
        $ref = (string) $order->reference;

        $prior = $priorMeta['batch_no'] ?? null;
        if (is_numeric($prior) && (int) $prior !== $refusedBatch) {
            try {
                $result = $this->client->getOrderCreateResult((int) $prior);
                foreach (($result['orders'] ?? []) as $o) {
                    if (is_array($o) && (string) ($o['order_id'] ?? '') === $ref && !empty($o['tracking_no'])) {
                        return ['order' => $o, 'batch_no' => $prior, 'status' => null];
                    }
                }
            } catch (\Throwable $e) {
                // falls through to Track
            }
        }

        $known = trim((string) $order->courier_tracking_number);
        foreach (array_values(array_unique(array_filter([$known, $ref]))) as $asked) {
            try {
                $data = $this->client->trackOrders([$asked]);
            } catch (\Throwable $e) {
                continue;
            }
            foreach (($data['orders'] ?? []) as $o) {
                $tracking = is_array($o) && is_string($o['tracking_no'] ?? null) ? trim($o['tracking_no']) : '';
                if ($tracking === '') {
                    continue;
                }
                $orderId = (string) ($o['order_id'] ?? '');
                if (!self::sameRecipientPhone($order, $o)) {
                    // Same order_id, another parcel (another system on this SPX account): never adopted.
                    continue;
                }
                if ($orderId === $ref || ($orderId === '' && $asked === $known && $tracking === $known)) {
                    $status = $o['status_code'] ?? null;

                    return [
                        // Track also answers names, phones and addresses: only what identifies the order is kept.
                        'order'    => array_intersect_key($o, array_flip(['order_id', 'tracking_no', 'tracking_link', 'status_code'])),
                        'batch_no' => null,
                        'status'   => is_scalar($status) && (string) $status !== '' ? (string) $status : null,
                    ];
                }
            }
        }

        return null;
    }

    /** False only when Track names a receiver phone that is not this shipment's (a phone SPX leaves out proves nothing). */
    private static function sameRecipientPhone(CourierOrder $order, array $tracked): bool
    {
        $theirs = PhPhone::normalize(is_scalar($tracked['deliver_info']['deliver_phone'] ?? null) ? (string) $tracked['deliver_info']['deliver_phone'] : null);
        $ours = PhPhone::normalize($order->shipping_phone ?: $order->phone);
        if ($theirs === null || $ours === null) {
            return true;
        }

        return substr($theirs, -10) === substr($ours, -10);
    }

    /** @return array{at:string, slot:string, label:string, reason:string} one pickup-time change for the shipment's timeline */
    private static function slotChange(array $slot, string $reason): array
    {
        return ['at' => now()->toIso8601String(), 'slot' => self::encodeSlot($slot), 'label' => self::slotLabel($slot), 'reason' => $reason];
    }

    /**
     * Whether this booking is collected: the shipment's `method`, else (a caller that sends none) whether a slot came.
     */
    private static function wantsPickup(array $opts): bool
    {
        return match ($opts['method'] ?? null) {
            'pickup' => true,
            'dropoff' => false,
            default => self::decodeSlot($opts['pickup_slot'] ?? null) !== null,
        };
    }

    /** @return array<string, mixed> the order's courier_meta, decoded */
    private static function metaOf(CourierOrder $order): array
    {
        $meta = $order->courier_meta ? json_decode($order->courier_meta, true) : null;

        return is_array($meta) ? $meta : [];
    }

    /**
     * 1.3 Cancel Order (batch_cancel_order). tracking_no_list is sent as a
     * REAL JSON array (SpxClient::cancelOrders() never comma-joins). 13002
     * (OrderUnableCancelCode) — surfaced either as the whole call's
     * ret_code, or per-tracking-number inside data.fail_list[] (api.md
     * §1.3's own example shows the latter) — gets a friendly message either
     * way rather than SPX's raw text.
     */
    public function cancelShipment(string $trackingNumber): array
    {
        if (!$this->client->hasAccountCredentials()) {
            return ['success' => false, 'error' => 'SPX User ID / User Secret not configured.'];
        }

        try {
            $data = $this->client->cancelOrders([$trackingNumber]);
        } catch (SpxApiException $e) {
            if ($e->retCode === 13002) {
                return ['success' => false, 'error' => self::CANCEL_TOO_LATE_MESSAGE];
            }
            return ['success' => false, 'error' => "SPX cancel failed: {$e->getMessage()} (ret_code {$e->retCode})"];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'SPX cancel failed: ' . $e->getMessage()];
        }

        $cancelled = $data['tracking_no_list'] ?? [];
        if (in_array($trackingNumber, $cancelled, true)) {
            return ['success' => true];
        }

        foreach (($data['fail_list'] ?? []) as $fail) {
            if (($fail['tracking_no'] ?? null) === $trackingNumber) {
                if ((int) ($fail['ret_code'] ?? 0) === 13002) {
                    return ['success' => false, 'error' => self::CANCEL_TOO_LATE_MESSAGE];
                }
                return ['success' => false, 'error' => $fail['message'] ?? 'SPX cancel failed.'];
            }
        }

        return ['success' => false, 'error' => 'SPX did not confirm the cancellation.'];
    }

    private const CANCEL_TOO_LATE_MESSAGE = 'This shipment has already been picked up (or it is too late to cancel). SPX only allows cancelling orders still pending pickup/drop-off.';

    /**
     * 1.2 Track Order (batch_search_order). Retries a bounded 2x on 13251
     * (order not yet propagated — SPX's timing caveat: don't call Track
     * immediately (<1-3s) after a successful Create).
     * api.md §1.2's own note says 13251 can surface EITHER way — the whole
     * call rejected with that ret_code, OR (ret_code:0 overall) this
     * tracking number landed in the per-item data.fail_list with that code
     * — so both surfaces are checked before deciding whether to back off and
     * retry (mirrors how cancelShipment() checks both surfaces for 13002).
     * Once the parcel is delivered or returned, also asks 2.2 for the actual
     * fee (actualFeeInfo()), at most once per parcel per day. Besides the
     * timeline it reports `actual_fee` and `meta` (tracking link, delivery
     * estimate, return fee), which App\Shipments\CourierFacts stores.
     */
    public function getTracking(string $trackingNumber): array
    {
        if (!$this->client->hasAccountCredentials()) {
            return ['success' => false, 'error' => 'SPX User ID / User Secret not configured.'];
        }

        $data = null;
        $lastError = null;
        $backoffMs = [1500, 3000];
        $attempts = count($backoffMs) + 1;

        for ($i = 0; $i < $attempts; $i++) {
            try {
                $data = $this->client->trackOrders([$trackingNumber]);
                $lastError = null;
            } catch (SpxApiException $e) {
                $lastError = $e;
                $data = null;
            } catch (\Throwable $e) {
                return ['success' => false, 'error' => 'SPX tracking failed: ' . $e->getMessage()];
            }

            $notYetPropagated = $lastError !== null && $lastError->retCode === 13251;
            if (!$notYetPropagated && is_array($data)) {
                foreach (($data['fail_list'] ?? []) as $fail) {
                    if (($fail['tracking_no'] ?? null) === $trackingNumber && (int) ($fail['ret_code'] ?? 0) === 13251) {
                        $notYetPropagated = true;
                        break;
                    }
                }
            }

            if (!$notYetPropagated) {
                break;
            }
            if ($i < $attempts - 1) {
                ($this->sleeper)($backoffMs[$i]);
            }
        }

        if ($lastError !== null) {
            return ['success' => false, 'error' => "SPX tracking failed: {$lastError->getMessage()} (ret_code {$lastError->retCode})"];
        }

        $found = null;
        foreach (($data['orders'] ?? []) as $o) {
            if (($o['tracking_no'] ?? null) === $trackingNumber) {
                $found = $o;
                break;
            }
        }

        if (!$found) {
            foreach (($data['fail_list'] ?? []) as $fail) {
                if (($fail['tracking_no'] ?? null) === $trackingNumber) {
                    return ['success' => false, 'error' => $fail['message'] ?? 'SPX tracking failed.'];
                }
            }
            return ['success' => false, 'error' => 'SPX returned no data for this tracking number.'];
        }

        $status = $found['status_code'] ?? $found['status'] ?? null;
        $status = is_scalar($status) ? (string) $status : null;
        $feeInfo = $this->actualFeeInfo($trackingNumber, $status);

        return [
            'success'    => true,
            'status'     => $status,
            'raw'        => $found,
            'links'      => !empty($found['tracking_link']) ? ['delivery' => $found['tracking_link']] : null,
            'timeline'   => self::timelineFromRoutes($found['routes'] ?? null),
            'fee_info'   => $feeInfo,
            'actual_fee' => is_array($feeInfo) ? Money::decimal($feeInfo['actual_shipping_fee'] ?? null) : null,
            'meta'       => array_filter([
                'tracking_links'    => self::deliveryLink($found['tracking_link'] ?? null),
                'edd'               => self::edd($found['edd_info']['edd_min'] ?? null, $found['edd_info']['edd_max'] ?? null, 'tracking'),
                'actual_return_fee' => is_array($feeInfo) ? self::positiveDecimal($feeInfo['return_shipping_fee'] ?? null) : null,
            ], fn ($v) => $v !== null),
        ];
    }

    /** Statuses at which SPX's actual fee is final enough to ask 2.2 for it: delivered, returned. */
    private const ACTUAL_FEE_STATUSES = ['4001', '6003'];

    /** How long one parcel waits before 2.2 is asked again (an unanswered or still-unknown fee). */
    private const ACTUAL_FEE_RETRY_SECONDS = 86400;

    /**
     * 2.2 Get Order Fee (batch_get_asf) for one parcel, or null. Asked only
     * once the parcel is delivered or returned, and at most once per parcel
     * per day (a cache mark per account and tracking number): the webhook
     * carries the running fee before that, and a poll used to ask on every
     * run (227 calls a day for 2 parcels). Best effort: a failure never
     * breaks tracking.
     *
     * @return array<string, mixed>|null SPX's fee_info
     */
    private function actualFeeInfo(string $trackingNumber, ?string $status): ?array
    {
        if (!in_array($status, self::ACTUAL_FEE_STATUSES, true)) {
            return null;
        }

        $key = 'spx:asf:' . $this->account->getKey() . ':' . $trackingNumber;
        if (!Cache::add($key, now()->getTimestamp(), self::ACTUAL_FEE_RETRY_SECONDS)) {
            return null;
        }

        try {
            $feeData = $this->client->getActualFee([$trackingNumber]);
        } catch (\Throwable $e) {
            return null;
        }

        foreach (($feeData['orders'] ?? []) as $fo) {
            if (is_array($fo) && ($fo['tracking_no'] ?? null) === $trackingNumber) {
                return is_array($fo['fee_info'] ?? null) ? $fo['fee_info'] : null;
            }
        }

        return null;
    }

    /** A decimal above zero, else null: SPX's documented examples answer 0 for a fee it has not set. */
    private static function positiveDecimal(mixed $value): ?string
    {
        $decimal = Money::decimal($value);

        return $decimal !== null && bccomp($decimal, '0', 2) > 0 ? $decimal : null;
    }

    /** Normalize SPX's routes[] history into the same {status,at} timeline shape QuadXFulfillment::timelineFromTat() produces. */
    public static function timelineFromRoutes(?array $routes): array
    {
        if (empty($routes)) {
            return [];
        }
        $events = [];
        foreach ($routes as $r) {
            $at = $r['timestamp'] ?? null;
            if (!is_int($at)) {
                continue;
            }
            $events[] = ['status' => (string) ($r['status_code'] ?? $r['status'] ?? ''), 'at' => $at];
        }
        usort($events, fn ($a, $b) => $a['at'] <=> $b['at']);
        return array_values($events);
    }

    /**
     * Final states — the poll stops re-checking once an order reaches one.
     * 5001/3001/6002 are NOT terminal (rider/return retries), mirroring how
     * QuadX's failed_pickup_attempt stays non-terminal.
     */
    public function terminalStatuses(): array
    {
        return [...self::DELIVERED, ...self::LOST_DAMAGED_STATUSES, '6003', ...self::CANCELLED];
    }

    /** "Only those orders with pending pickup/dropoff status can be cancelled" (guide.md's 13002 recall in api.md §1.3). */
    public function cancelableStatuses(): array
    {
        return ['1001'];
    }

    /**
     * Buckets every SPX status code (api.md's order status table) plus the
     * two internal bridging statuses THIS courier introduces ('creating' —
     * async create still in flight; 'creation_failed' — async create Done
     * but permanently failed, see resolvePendingCreation()) onto the common
     * 8-phase spine.
     */
    public function statusPhase(?string $status): array
    {
        $s = (string) $status;

        if ($s === 'creating') {
            // The async-create pending state maps to phase booking.
            return ['phase' => 'booking', 'step' => 1, 'failed' => false];
        }
        if ($s === 'creation_failed') {
            return ['phase' => 'failed', 'step' => 1, 'failed' => true];
        }
        if (in_array($s, self::DELIVERED, true)) {
            return ['phase' => 'delivered', 'step' => 3, 'failed' => false];
        }
        // Alongside SPX's own 7001, the literal 'canceled'/'cancelled' the
        // admin Cancel action stamps (FulfillmentController::cancel() writes
        // it for EVERY courier) must bucket to the cancelled phase too —
        // otherwise an admin-cancelled SPX order would read as 'to_handover'
        // until the next poll/webhook overwrote it with 7001.
        if (in_array($s, self::CANCELLED, true) || in_array($s, CourierOrder::CANCELED_STATUSES, true)) {
            return ['phase' => 'cancelled', 'step' => 1, 'failed' => true];
        }
        if (in_array($s, self::RETURNING_STATUSES, true)) {
            return ['phase' => 'returning', 'step' => 2, 'failed' => true];
        }
        if (in_array($s, self::LOST_DAMAGED_STATUSES, true)) {
            return ['phase' => 'lost_damaged', 'step' => 2, 'failed' => true];
        }
        if (in_array($s, self::ON_HOLD_STATUSES, true)) {
            // Held mid-delivery: the seller may need to look, but nothing has failed for good.
            return ['phase' => 'attention', 'step' => 2, 'failed' => false];
        }
        if (in_array($s, self::FAILED_STATUSES, true)) {
            $prePickup = in_array($s, self::FAILED_PRE_PICKUP, true);
            return ['phase' => 'failed', 'step' => $prePickup ? 1 : 2, 'failed' => true];
        }
        if (in_array($s, self::IN_TRANSIT_STATUSES, true)) {
            return ['phase' => 'in_transit', 'step' => 2, 'failed' => false];
        }

        // 1001 Pending Pickup, and any unrecognized/unset status: booked,
        // still awaiting courier collection/drop-off.
        return ['phase' => 'to_handover', 'step' => 1, 'failed' => false];
    }

    /**
     * 2.1 Check Shipping Fee (batch_check_order) — estimated fee BEFORE
     * booking. Reuses buildOrderPayload() (same shape createShipment() would
     * submit); returns null (the interface's sanctioned "unsupported/
     * unavailable" default) rather than a partial guess whenever the order's
     * address or the store's pickup origin isn't complete enough to build a
     * real payload — an estimate from incomplete data would mislead the
     * operator committing money from this screen.
     */
    public function estimateFee(CourierOrder $order, array $options): ?object
    {
        if (!$this->client->hasAccountCredentials()) {
            return null;
        }

        $built = $this->buildOrderPayload($order, $options);
        if (!$built['ok']) {
            return null;
        }

        try {
            $data = $this->client->checkOrderFee([$built['payload']]);
        } catch (\Throwable $e) {
            return null;
        }

        $first = ($data['orders'] ?? [])[0] ?? null;
        if (!$first || !isset($first['estimated_shipping_fee'])) {
            return null;
        }

        $amount = (float) $first['estimated_shipping_fee'];
        return (object) [
            'amount'          => $amount,
            'currency'        => 'PHP',
            'label'           => '₱' . number_format($amount, 2),
            'remote_area_fee' => Money::decimal($first['remote_area_fee'] ?? null),
            'odz'             => self::odz($first),
        ];
    }

    /**
     * Out of SPX's normal delivery zone: remote_area_fee above zero. The field
     * is undocumented (seen in the real 1.5 answer, not promised for 2.1), so
     * an answer without it is "not known", never "no surcharge".
     *
     * @param array<string, mixed> $order one 2.1 or 1.5 order entry
     */
    private static function odz(array $order): ?bool
    {
        $fee = Money::decimal($order['remote_area_fee'] ?? null);

        return $fee === null ? null : bccomp($fee, '0', 2) > 0;
    }

    /**
     * SPX's serviceability answer, from the same 2.1 batch_check_order call
     * that quotes the fee, the only pre-booking question SPX will answer at
     * all (it otherwise refuses at create time, with the codes below).
     *
     * Implemented against portal/api/09 (2.1) and guide/08's error tables;
     * 2.1 itself has not yet been called against SPX from this install (the
     * UAT host `test-stable.spx.ph` resolves and answers the other calls).
     * An unreachable SPX (DNS, connection, timeout) is caught below as
     * `unknown` ("could not check", still clickable), never as a refusal. If
     * this ever reported "not serviceable" for an SPX outage, the modal would
     * be lying about coverage SPX never evaluated.
     *
     * Every code is classified by GROUP (see REFUSAL_GROUPS /
     * UNCERTAIN_GROUPS), so an unseen code still lands somewhere sensible.
     * Only the four refusal groups make the card inert; a parcel complaint, a
     * pickup-slot complaint and an SPX-side failure all leave it clickable.
     */
    public function checkServiceability(CourierOrder $order, PickupPoint $from): ServiceabilityVerdict
    {
        if (!$this->client->hasAccountCredentials()) {
            return ServiceabilityVerdict::unknown(ServiceabilityVerdict::KIND_UNREACHABLE);
        }

        // No booking options exist yet at this point in the modal (the
        // courier has not even been chosen), so the payload is built with
        // defaults: buildOrderPayload() falls back to the first pouch
        // preset, which is the smallest and therefore the cheapest, making
        // the quoted fee a genuine from-price.
        $built = $this->buildOrderPayload($order, ['pickup' => $from]);
        if (!$built['ok'] && isset($built['kind'])) {
            // SPX's own location list rules the address out: a refusal we
            // can state without asking SPX.
            return ServiceabilityVerdict::notServiceable($built['kind'], null, $built['code'] ?? null);
        }
        if (!$built['ok']) {
            // Our own missing data (an incomplete pickup origin, an address
            // we cannot map), so nothing was asked and nothing was refused.
            // `code` tells the API client which field to fix.
            return ServiceabilityVerdict::unknown(ServiceabilityVerdict::KIND_UNREACHABLE, null, $built['code'] ?? null);
        }

        try {
            $data = $this->client->checkOrderFee([$built['payload']], ServiceabilityService::TIMEOUT_SECONDS);
        } catch (SpxApiException $e) {
            // ->retCode, never getCode(): SpxApiException carries SPX's own
            // code in its own property and leaves the RuntimeException code
            // at zero.
            return self::verdictForCode($e->retCode, $e->getMessage());
        } catch (\Throwable $e) {
            // Transport: DNS, connection, timeout, or a response that was not
            // an SPX envelope at all. We could not ask, and the message is
            // curl's rather than SPX's, so it is logged and never shown.
            Log::warning('SPX serviceability check could not reach SPX', [
                'order' => $order->id, 'error' => $e->getMessage(),
            ]);

            return ServiceabilityVerdict::unknown(ServiceabilityVerdict::KIND_UNREACHABLE);
        }

        $first = ($data['orders'] ?? [])[0] ?? null;
        if (is_array($first) && isset($first['estimated_shipping_fee'])) {
            return ServiceabilityVerdict::serviceable(
                ['estimate' => (float) $first['estimated_shipping_fee']],
                'PHP'
            )->withOdz(self::odz($first));
        }

        // api.md §2.1: a per-order failure comes back inside data.fail_list
        // with its own ret_code, rather than failing the whole envelope.
        $failed = ($data['fail_list'] ?? [])[0] ?? null;
        if (is_array($failed)) {
            return self::verdictForCode((int) ($failed['ret_code'] ?? 0), (string) ($failed['message'] ?? ''));
        }

        return ServiceabilityVerdict::unknown(ServiceabilityVerdict::KIND_THEIR_SIDE);
    }

    /** One SPX error code, classified by group and given its `code` (VERDICT_CODES), with SPX's own words. */
    private static function verdictForCode(int $code, string $message): ServiceabilityVerdict
    {
        [$kind, $isRefusal] = self::classify($code);
        $said = trim($message) !== '' ? trim($message) : null;

        return $isRefusal
            ? ServiceabilityVerdict::notServiceable($kind, $said, self::verdictCode($code))
            : ServiceabilityVerdict::unknown($kind, $said, self::verdictCode($code));
    }

    /**
     * 1.1 Get Pickup Timeslot (get_pickup_time): every window SPX offers, one
     * option per window, in SPX's own order (days as SPX lists them; within a
     * day SPX's range id 0 "Now" first, then SPX's order, never re-sorted).
     * Each option's `value` is the small JSON blob carrying exactly the three
     * fields SPX's create endpoint needs (pickup_time, pickup_time_range_id,
     * pickup_time_range), which createShipment() decodes back out of
     * $opts['pickup_slot'] unchanged. Which days and windows appear is SPX's
     * own decision (its cutoff); a day before today (Manila) or a day with no
     * usable window is skipped, never shown as an empty option.
     *
     * 1.1 takes no address: SPX answers for the account (user_id), so the
     * $order is not read.
     */
    public function getPickupSlots(CourierOrder $order): array
    {
        try {
            $slots = $this->availableSlots();
        } catch (\Throwable $e) {
            return [];
        }

        return array_map(fn (array $slot) => [
            'value' => self::encodeSlot($slot),
            'label' => self::slotLabel($slot),
        ], $slots);
    }

    /**
     * SPX's pickup windows from 1.1, earliest first as SPX orders them.
     *
     * @return list<array{pickup_time:int, pickup_time_range_id:int, pickup_time_range:string}>
     * @throws \Throwable when SPX could not be asked
     */
    private function availableSlots(): array
    {
        if (!$this->client->hasAccountCredentials()) {
            return [];
        }

        $days = $this->client->getPickupTime(1);
        $today = now(config('app.timezone'))->format('Y-m-d');

        $options = [];
        foreach (is_array($days) ? $days : [] as $day) {
            $pickupTime = $day['pickup_time'] ?? null;
            $slots = $day['slots'] ?? null;
            if (!is_numeric($pickupTime) || !is_array($slots) || $slots === []) {
                continue;
            }
            if (self::slotDate((int) $pickupTime) < $today) {
                continue;
            }

            $now = [];
            $later = [];
            foreach ($slots as $slot) {
                if (!is_array($slot) || !is_numeric($slot['pickup_time_range_id'] ?? null)) {
                    continue;
                }
                $entry = [
                    'pickup_time'          => (int) $pickupTime,
                    'pickup_time_range_id' => (int) $slot['pickup_time_range_id'],
                    'pickup_time_range'    => (string) ($slot['pickup_time_range'] ?? ''),
                ];
                if ($entry['pickup_time_range_id'] === 0) {
                    $now[] = $entry;
                } else {
                    $later[] = $entry;
                }
            }
            array_push($options, ...$now, ...$later);
        }

        return $options;
    }

    /**
     * The window to book: the earliest SPX offers now, or, after $refused (a
     * window SPX just turned down), the one SPX lists right after it. A
     * window SPX no longer lists at all gives way to the earliest one.
     *
     * @param array{pickup_time:int, pickup_time_range_id:int, pickup_time_range:string}|null $refused
     * @return array{ok:true, slot:array{pickup_time:int, pickup_time_range_id:int, pickup_time_range:string}}|array{ok:false, error:string}
     */
    private function nextSlot(?array $refused): array
    {
        try {
            $slots = $this->availableSlots();
        } catch (\Throwable $e) {
            Log::warning('SPX pickup times could not be fetched for a booking', ['error' => $e->getMessage()]);

            return ['ok' => false, 'error' => "Couldn't get SPX's pickup times. Try again."];
        }

        if ($refused !== null) {
            foreach ($slots as $i => $slot) {
                if ($slot['pickup_time'] === $refused['pickup_time'] && $slot['pickup_time_range_id'] === $refused['pickup_time_range_id']) {
                    $slots = array_slice($slots, $i + 1);
                    break;
                }
            }
        }

        return $slots === []
            ? ['ok' => false, 'error' => self::NO_PICKUP_TIME]
            : ['ok' => true, 'slot' => $slots[0]];
    }

    private const NO_PICKUP_TIME = 'SPX has no pickup time available for this address.';

    /** @param array{pickup_time:int, pickup_time_range_id:int, pickup_time_range:string} $slot */
    private static function encodeSlot(array $slot): string
    {
        return (string) json_encode([
            'pickup_time'          => (int) $slot['pickup_time'],
            'pickup_time_range_id' => (int) $slot['pickup_time_range_id'],
            'pickup_time_range'    => (string) $slot['pickup_time_range'],
        ]);
    }

    /** "Tue, Nov 10 · 2:00pm-4:00pm": the day (Manila) and SPX's own window text. */
    public static function slotLabel(array $slot): string
    {
        $day = \Carbon\Carbon::createFromTimestamp((int) ($slot['pickup_time'] ?? 0))
            ->timezone(config('app.timezone'))
            ->format('D, M d');
        $range = trim((string) ($slot['pickup_time_range'] ?? ''));

        return $range === '' ? $day : "{$day} · {$range}";
    }

    /** The label of a stored `pickup_slot` value; null when it is not an SPX slot. */
    public static function describeSlot(mixed $value): ?string
    {
        $slot = self::decodeSlot($value);

        return $slot === null ? null : self::slotLabel($slot);
    }

    /** The day (Y-m-d, Manila) of a stored `pickup_slot` value; null when it is not an SPX slot. */
    public static function slotDay(mixed $value): ?string
    {
        $slot = self::decodeSlot($value);

        return $slot === null ? null : self::slotDate($slot['pickup_time']);
    }

    /** Y-m-d in the app zone (Manila) of a 1.1 pickup_time. */
    private static function slotDate(int $pickupTime): string
    {
        return \Carbon\Carbon::createFromTimestamp($pickupTime)->timezone(config('app.timezone'))->format('Y-m-d');
    }

    /**
     * A slot whose day is already over (Manila). A window later today is left
     * for SPX to judge: 1.1 does not say what pickup_time marks within the
     * day, and SPX's 10080 answer is handled by createShipment().
     */
    private static function slotPassed(array $slot): bool
    {
        return self::slotDate((int) $slot['pickup_time']) < now(config('app.timezone'))->format('Y-m-d');
    }

    /**
     * 1.6 Get AWB V2 (batch_get_shipping_label). SPX's own label
     * link is PERISHABLE (api.md §1.6: "valid within half an hour, and can
     * be opened up to 10 times"), so this never hands that raw link back to
     * an admin who might revisit the page later and find it dead or
     * exhausted. Instead:
     *
     *  1. Look up the order this tracking number belongs to (SPX's
     *     batch_get_shipping_label needs the batch_no from booking — this
     *     interface only gives us a tracking number, so the order is the
     *     only place that batch_no is still available; see
     *     buildOrderPayload()/createShipment() for where it's first stored
     *     into courier_meta).
     *  2. If we already downloaded+stored the PDF for this tracking number
     *     (Storage::disk('local')->exists()), skip straight to step 4 — no
     *     SPX call at all on repeat visits.
     *  3. Otherwise fetch it via pollLabelResult()'s two-phase strategy
     *     (documented v2 endpoint first, short window; an UNDOCUMENTED v1
     *     fallback if that doesn't settle — see that method's docblock for
     *     why, this is observed UAT behavior, not documented behavior),
     *     then download the resulting awb_link PDF bytes and store them at
     *     `labels/spx/{tracking}.pdf`.
     *  4. Return that stored path — never SPX's own link — per the
     *     interface's sanctioned evolution #2 (see its docblock).
     *
     * $format is accepted for interface-contract compatibility but ignored:
     * SPX documents exactly one label shape (a single PDF), unlike QuadX's
     * zebra/fuji choice.
     */
    public function printLabel(string $trackingNumber, string $format): array
    {
        // The number becomes part of a storage path: only a plain token is ever looked up or stored.
        if (!self::isPlainTrackingNumber($trackingNumber)) {
            return ['success' => false, 'error' => 'That is not an SPX tracking number.'];
        }

        $order = CourierOrder::forTracking($this->getKey(), $trackingNumber, $this->account);
        if (!$order) {
            return ['success' => false, 'error' => 'No SPX order found for this tracking number.'];
        }

        // api.md §1.6 — a cancelled order's
        // AWB cannot be printed. Refused server-side (the queue's button
        // gating is only UI), and BEFORE the cache check: a PDF cached
        // while the booking was live must not keep serving after the
        // shipment is cancelled — that label's AWB is void.
        if (($this->statusPhase($order->courier_status)['phase'] ?? null) === 'cancelled') {
            return ['success' => false, 'error' => 'This SPX booking is cancelled. Cancelled shipments have no printable waybill. Rebook the order to get a fresh label.'];
        }

        $path = self::labelStoragePath($trackingNumber);
        $disk = Storage::disk('local');

        if (!$disk->exists($path)) {
            $stored = $this->fetchAndStoreLabel($order, $path);
            if (!$stored['ok']) {
                return ['success' => false, 'error' => $stored['error']] + (isset($stored['unsafe']) ? ['unsafe' => true] : []);
            }
        }

        // The stored PDF on the local (private) disk; the waybill service serves it.
        return [
            'success' => true,
            'path'    => $path,
        ];
    }

    /** Where a downloaded AWB is kept on the local (private) disk, served by the label store. */
    public static function labelStoragePath(string $trackingNumber): string
    {
        return "labels/spx/{$trackingNumber}.pdf";
    }

    /** Letters, digits, dash and underscore only: safe as a file name. */
    private static function isPlainTrackingNumber(string $trackingNumber): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{1,64}$/', $trackingNumber) === 1;
    }

    /**
     * @return array{ok:bool, error?:string}
     */
    private function fetchAndStoreLabel(CourierOrder $order, string $path): array
    {
        if (!$this->client->hasAccountCredentials()) {
            return ['ok' => false, 'error' => 'SPX User ID / User Secret not configured.'];
        }

        $meta = $order->courier_meta ? (json_decode($order->courier_meta, true) ?: []) : [];
        $batchNo = $meta['batch_no'] ?? null;
        if (!$batchNo) {
            return ['ok' => false, 'error' => 'No SPX batch number on file for this order. Cannot fetch a label.'];
        }

        $result = $this->pollLabelResult((int) $batchNo, $order->courier_tracking_number);
        if ($result instanceof SpxApiException) {
            return ['ok' => false, 'error' => "SPX label fetch failed: {$result->getMessage()} (ret_code {$result->retCode})"];
        }
        if ($result === null) {
            return ['ok' => false, 'error' => 'SPX label generation is still in progress, try again shortly.'];
        }

        // Observed on UAT: a batch_get_shipping_label response can carry NO
        // `task_status` key at all — it returns `{awb_link, fail_list}`
        // immediately, not the documented submit-then-poll
        // {task_status: 1..5} shape. So "done" is decided by
        // awb_link's PRESENCE (plus this tracking number not appearing in
        // fail_list), not by task_status alone. The documented shape is
        // still honored when task_status IS present: 5 (Failed) always
        // fails regardless of awb_link; 4 (Done) with no awb_link is the
        // "all orders failed to create" trap createShipment() already knows
        // about (SpxFulfillment::outcomeFromCreateResult()'s docblock notes
        // the same "Done but nothing usable" possibility for 1.5 — 1.6
        // apparently shares it).
        $taskStatus = array_key_exists('task_status', $result) ? (int) $result['task_status'] : null;
        $awbLink = $result['awb_link'] ?? null;

        $failEntry = null;
        foreach (($result['fail_list'] ?? []) as $fail) {
            if (($fail['tracking_no'] ?? null) === $order->courier_tracking_number) {
                $failEntry = $fail;
                break;
            }
        }

        if ($failEntry !== null) {
            return ['ok' => false, 'error' => $failEntry['message'] ?? 'SPX could not generate a label for this shipment.'];
        }
        if ($taskStatus === 5) {
            $desc = $result['description'] ?? 'SPX marked this label batch as Failed.';
            return ['ok' => false, 'error' => "SPX label generation failed: {$desc}"];
        }
        if (empty($awbLink)) {
            $desc = $result['description'] ?? 'SPX could not generate a label for this order.';
            return ['ok' => false, 'error' => "SPX label generation failed: {$desc}"];
        }

        // The link comes out of SPX's answer: only an https link on SPX's own hosts is ever fetched.
        if (!\App\Couriers\Support\LabelHosts::allows('spx', $awbLink)) {
            Log::warning('SPX label link refused: host not on the allow-list', ['host' => parse_url((string) $awbLink, PHP_URL_HOST)]);

            return ['ok' => false, 'unsafe' => true, 'error' => 'SPX returned a label link that is not on an SPX host.'];
        }

        try {
            $pdf = Http::timeout(30)->withoutRedirecting()->get($awbLink);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Failed to download the SPX label PDF: ' . $e->getMessage()];
        }

        if (!$pdf->successful() || $pdf->body() === '') {
            return ['ok' => false, 'error' => "SPX label download failed (HTTP {$pdf->status()})."];
        }

        // Observed on UAT: awb_link can respond HTTP 200 with
        // a JSON error body instead of a PDF (UAT's label RENDERER failing
        // server-side, downstream of a perfectly valid awb_link — this is
        // NOT the same failure class as `$pdf->successful()` catches, which
        // only sees transport-level success). Content-Type isn't trustworthy
        // enough on its own (SPX's UAT error response has been observed
        // still under a generic content type), so the actual bytes are
        // checked for the PDF magic number before ever touching disk — a
        // corrupt "PDF" must never reach an admin's download.
        $body = $pdf->body();
        if (!str_starts_with($body, '%PDF')) {
            $decoded = json_decode($body, true);
            if (is_array($decoded) && (isset($decoded['retcode']) || isset($decoded['message']))) {
                $retcode = $decoded['retcode'] ?? 'unknown';
                $message = $decoded['message'] ?? 'unknown error';
                return ['ok' => false, 'error' => "SPX label service error: {$message} (retcode {$retcode})"];
            }
            return ['ok' => false, 'error' => 'SPX returned a label that is not a valid PDF.'];
        }

        Storage::disk('local')->put($path, $body);

        return ['ok' => true];
    }

    /**
     * Two-phase label fetch, shaped by how SPX's UAT actually behaves.
     *
     * Phase 1 — 1.6 Get AWB V2 (the DOCUMENTED endpoint, SpxClient::
     * getShippingLabel()): a SHORT window only (2 attempts, one brief
     * backoff between them) rather than a long bounded poll. On UAT this
     * endpoint returns the documented shape (task_status present) but
     * reliably sits at `task_status: 3` ("Running", `progress: "0.00%"`)
     * INDEFINITELY (probed 8+ minutes, never changes), so a long poll
     * window would only burn time to arrive at the same "still stuck"
     * outcome a short window reaches just as certainly, faster. A short
     * window still gives v2 every fair chance to settle immediately, in
     * case it behaves as documented after all (plausible on production,
     * unverified — see SpxClient::getShippingLabelV1()'s docblock) — it
     * just no longer BLOCKS on the assumption that it will.
     *
     * Phase 2 — the UNDOCUMENTED v1 fallback (SpxClient::
     * getShippingLabelV1(), batch_no + tracking_no_list): reached only if
     * phase 1 never settled (whether by timing out on "Running" for the
     * whole short window, or by v2 throwing an SpxApiException — a
     * well-formed error there doesn't rule out the fallback succeeding, so
     * it's still attempted). Live-probed to return `{awb_link, fail_list}`
     * SYNCHRONOUSLY. Its response is run through the EXACT SAME
     * labelResultSettled()/fetchAndStoreLabel() parse logic as v2's.
     *
     * Error precedence when NEITHER phase settles: an SpxApiException from
     * v1 (the more recent, more specific attempt) wins; otherwise an
     * SpxApiException from v2 (if one was seen) is surfaced; otherwise null
     * ("still in progress" — genuinely nothing usable came back from
     * either endpoint). A bare network/transport Throwable (either phase)
     * is treated as transient, never surfaced directly.
     *
     * @return array|SpxApiException|null the raw response data (v2 OR v1
     *   shape — both parsed identically downstream) once settled; the most
     *   relevant caught SpxApiException if neither settled but SPX returned
     *   a well-formed error; or null if truly nothing usable came back.
     */
    private function pollLabelResult(int $batchNo, string $trackingNumber): array|SpxApiException|null
    {
        $v2BackoffMs = [1500];
        $v2Attempts = count($v2BackoffMs) + 1;
        $v2Exception = null;

        for ($i = 0; $i < $v2Attempts; $i++) {
            try {
                $result = $this->client->getShippingLabel($batchNo);
            } catch (SpxApiException $e) {
                // A well-formed v2 error settles NOTHING but isn't worth
                // retrying against the same batch_no either — stop this
                // phase now and let phase 2 have its shot.
                $v2Exception = $e;
                break;
            } catch (\Throwable $e) {
                $result = null;
            }

            if (isset($result) && is_array($result) && self::labelResultSettled($result)) {
                return $result;
            }

            if ($i < $v2Attempts - 1) {
                ($this->sleeper)($v2BackoffMs[$i]);
            }
        }

        try {
            $v1Result = $this->client->getShippingLabelV1($batchNo, [$trackingNumber]);
        } catch (SpxApiException $e) {
            return $e;
        } catch (\Throwable $e) {
            $v1Result = null;
        }

        if (is_array($v1Result) && self::labelResultSettled($v1Result)) {
            return $v1Result;
        }

        return $v2Exception;
    }

    /**
     * "Stop polling" test for 1.6 Get AWB V2's response, covering BOTH the
     * documented submit-then-poll shape and the real UAT shape (see
     * pollLabelResult()'s docblock):
     *  - `awb_link` present -> a label is ready, regardless of whether
     *    `task_status` exists at all (the UAT shape never sends it).
     *  - `fail_list` non-empty -> a definitive per-tracking-number failure,
     *    even with no `awb_link` and no `task_status`.
     *  - `task_status` present and settled (4 Done / 5 Failed) -> the
     *    documented shape's own terminal signal, honored even if `awb_link`
     *    happens to be empty (fetchAndStoreLabel() turns that combination
     *    into a clear error rather than treating it as still-pending).
     */
    private static function labelResultSettled(array $result): bool
    {
        if (!empty($result['awb_link'])) {
            return true;
        }
        if (!empty($result['fail_list'])) {
            return true;
        }

        return array_key_exists('task_status', $result)
            && in_array((int) $result['task_status'], self::CREATE_RESULT_SETTLED, true);
    }

    /**
     * 4.1 Tracking Web Hook (api.md §4.1) — SPX's callback body is ALREADY
     * one event (unlike QuadX's `{data:[...]}` batch shape), so this always
     * returns at most one normalized event. `order_id` is what WE sent as
     * `order_id` at booking time (buildOrderPayload(): the shipment's reference)
     * — SPX's docs call it "customer order id", which is exactly this
     * interface's `reference_id`.
     *
     * Besides the status, an event carries `actual_fee` (actual_shipping_fee,
     * "refer to the latest one you received") and `meta`: the tracking link,
     * the delivery estimate (edd_min/edd_max), the driver, the
     * proof_of_delivery_list photos and, on 6003, the return fee.
     *
     * A payload without `status_code` is 4.2 Evidence Proofs when it carries
     * proof lists: one event with no status and the proofs as `meta`, which
     * ProcessReceipt keeps on the matching shipment. Anything else without
     * `status_code` yields no events rather than a guessed one.
     */
    public function parseWebhook(array $payload): array
    {
        if (!isset($payload['status_code'])) {
            return self::evidenceEvent($payload);
        }

        $timestamp = $payload['timestamp'] ?? null;

        return [array_filter([
            'reference_id'    => isset($payload['order_id']) ? (string) $payload['order_id'] : null,
            'tracking_number' => isset($payload['tracking_no']) ? (string) $payload['tracking_no'] : null,
            'status'          => (string) $payload['status_code'],
            'occurred_at'     => is_numeric($timestamp) ? date(DATE_ATOM, (int) $timestamp) : null,
            'remarks'         => isset($payload['message']) ? (string) $payload['message'] : null,
            'actual_fee'      => Money::decimal($payload['actual_shipping_fee'] ?? null),
            'meta'            => array_filter([
                'tracking_links'    => self::deliveryLink($payload['tracking_link'] ?? null),
                'edd'               => self::edd($payload['edd_min'] ?? null, $payload['edd_max'] ?? null, 'webhook'),
                'driver'            => array_filter([
                    'name'  => self::shortText($payload['driver_name'] ?? null, 100),
                    'phone' => self::shortText($payload['driver_phone_number'] ?? null, 32),
                ]) ?: null,
                'proofs'            => array_filter(['epod' => self::urls($payload['proof_of_delivery_list'] ?? null)]) ?: null,
                'actual_return_fee' => self::positiveDecimal($payload['actual_return_shipping_fee'] ?? null),
            ], fn ($v) => $v !== null) ?: null,
        ], fn ($v, $k) => $v !== null || in_array($k, ['reference_id', 'tracking_number', 'occurred_at', 'remarks'], true), ARRAY_FILTER_USE_BOTH)];
    }

    /** The 4.2 proof lists, by the key each is stored under in meta.proofs. */
    private const EVIDENCE_LISTS = ['epod' => 'epod_list', 'epop' => 'epop_list', 'epor' => 'epor_list', 'epooh' => 'epooh_list'];

    /**
     * 4.2 Evidence Proofs: matched by tracking_no, else customer_order_id (our order_id).
     *
     * @return list<array<string, mixed>>
     */
    private static function evidenceEvent(array $payload): array
    {
        $proofs = [];
        foreach (self::EVIDENCE_LISTS as $key => $field) {
            if (($urls = self::urls($payload[$field] ?? null)) !== null) {
                $proofs[$key] = $urls;
            }
        }
        $tracking = isset($payload['tracking_no']) && is_scalar($payload['tracking_no']) ? (string) $payload['tracking_no'] : null;
        $reference = isset($payload['customer_order_id']) && is_scalar($payload['customer_order_id']) ? (string) $payload['customer_order_id'] : null;
        if ($proofs === [] || ($tracking === null && $reference === null)) {
            return [];
        }

        return [[
            'reference_id'    => $reference,
            'tracking_number' => $tracking,
            'status'          => null,
            'occurred_at'     => null,
            'remarks'         => null,
            'meta'            => ['proofs' => $proofs],
        ]];
    }

    /**
     * Verifies an inbound SPX webhook request's signature —
     * see SpxClient::verifyWebhookSignature()'s docblock for the algorithm.
     * Uses $request->getContent() (the raw wire bytes), never
     * $request->all() (which would re-encode and could byte-mismatch).
     */
    public function verifyWebhookSignature(Request $request): bool
    {
        return $this->client->verifyWebhookSignature(
            $request->getContent(),
            $request->header('app-id'),
            $request->header('check-sign'),
            $request->header('timestamp'),
            $request->header('random-num'),
        );
    }

    /**
     * SPX's public tracking page for this parcel, as SPX itself gave it: the
     * booking's tracking_link. Preference order: meta.tracking_links.delivery
     * (kept from the booking answer, a webhook or a tracking poll), else
     * booked_response.tracking_link (1.5's own answer, before anything else
     * ran). Older code read `tracking_no_link`, a name SPX never sends; it is
     * still accepted for responses stored that way.
     *
     * Never built from the tracking number alone: SPX documents no public
     * tracker address, and the live host is unconfirmed (the sandbox one is
     * uat.spx.ph). A booking with no link from SPX (one recorded by hand)
     * therefore has none.
     */
    public function publicTrackingUrl(string $trackingNumber, array $courierMeta): ?string
    {
        foreach ([$courierMeta['tracking_links']['delivery'] ?? null, $courierMeta['booked_response']['tracking_link'] ?? null,
            $courierMeta['booked_response']['tracking_no_link'] ?? null] as $candidate) {
            if (($url = self::url($candidate)) !== null) {
                return $url;
            }
        }

        return null;
    }

    public function trackingLinks(CourierOrder $order): array
    {
        $url = $this->publicTrackingUrl((string) $order->courier_tracking_number, self::metaOf($order));

        return $url === null ? [] : [['label' => 'Track', 'url' => $url]];
    }

    /**
     * SPX's 3.2 Check Account
     * Credentials (`POST /open/api/v1/account/verify`) is the cheapest
     * authenticated call in the whole API surface — no order side-effects —
     * so it doubles as "test connection". Graceful (never throws) when
     * either credential pair is missing, and on any SPX-side failure.
     */
    public function testConnection(): array
    {
        if (!$this->client->isConfigured()) {
            return ['ok' => false, 'message' => 'App ID / App Secret not set.'];
        }
        if (!$this->client->hasAccountCredentials()) {
            return ['ok' => false, 'message' => 'User ID / User Secret not set.'];
        }

        try {
            $data = $this->client->verifyAccount();
        } catch (SpxApiException $e) {
            return ['ok' => false, 'message' => Str::limit("Failed: {$e->getMessage()} (ret_code {$e->retCode})", 157)];
        } catch (\Throwable $e) {
            // The client wraps a transport failure in a RuntimeException.
            if ($e instanceof \Illuminate\Http\Client\ConnectionException || $e->getPrevious() instanceof \Illuminate\Http\Client\ConnectionException) {
                return ['ok' => false, 'transport' => true, 'message' => 'No answer from SPX.'];
            }

            return ['ok' => false, 'message' => Str::limit('Failed: ' . $e->getMessage(), 157)];
        }

        return !empty($data['match_result'])
            ? ['ok' => true, 'message' => 'Connected. SPX reachable and credentials valid.']
            : ['ok' => false, 'message' => 'SPX reachable, but the User ID / User Secret pair did not match.'];
    }

    /**
     * 3.1 Create Account: issues the user_id/user_secret pair every order-related SPX call needs, for an account that
     * has the app pair but no user pair yet. Not part of Courier (no other courier has an equivalent).
     *
     * The phone (SPX requires one PH mobile) and the email are the operator's, typed for this account; nothing is
     * taken from a pickup address or this app's mail settings. The email is optional for SPX: pass '' to send none.
     *
     * Refused, before any call, while a User ID is already on file: replacing the pair would silently orphan every
     * booking made under the old one (their tracking, cancel and label calls would all send the new secret).
     *
     * The returned User ID is stored plain and the User Secret encrypted, matching settingsSchema().
     *
     * @return array{ok:bool, message?:string, error?:string}
     */
    public function createShipperAccount(string $phone, string $email): array
    {
        if ($this->settings->read($this->client->credentialKey('user_id')) !== null) {
            return ['ok' => false, 'error' => 'An SPX shipper account is already on file (User ID set). Remove it first if you really need to replace it, so existing bookings are not silently orphaned.'];
        }

        if (!$this->client->isConfigured()) {
            return ['ok' => false, 'error' => 'Set the App ID / App Secret before creating a shipper account.'];
        }

        $normalized = $this->normalizePhPhone($phone);
        if ($normalized === null) {
            return ['ok' => false, 'error' => 'Enter a valid PH mobile number for the shipper account.'];
        }

        $email = trim($email);
        if ($email !== '' && (mb_strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
            return ['ok' => false, 'error' => 'Enter a valid email address for the shipper account, or none.'];
        }

        try {
            $data = $this->client->createAccount($normalized, $email !== '' ? $email : null);
        } catch (SpxApiException $e) {
            return ['ok' => false, 'error' => "SPX account creation failed: {$e->getMessage()} (ret_code {$e->retCode})"];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'SPX account creation failed: ' . $e->getMessage()];
        }

        $userId = $data['user_id'] ?? null;
        $userSecret = $data['user_secret'] ?? null;
        if (!$userId || !$userSecret || !is_scalar($userId) || !is_scalar($userSecret)) {
            return ['ok' => false, 'error' => 'SPX did not return a user id/secret.'];
        }

        // Both or neither: a User ID stored without its secret would read as an account on file that cannot book.
        DB::transaction(function () use ($userId, $userSecret) {
            $this->settings->write($this->client->credentialKey('user_id'), (string) $userId);
            $this->settings->writeSecret($this->client->credentialKey('user_secret'), (string) $userSecret);
        });

        return ['ok' => true, 'message' => "Shipper account created (User ID {$userId})."];
    }

    /**
     * No LIVE status-vocabulary endpoint exists in the documented API
     * surface, but the vocabulary itself is not
     * actually unknown — SPX documents it as a stable table (STATUS_LABELS,
     * sourced from docs/couriers/spx/guide.md's "Order status code" section). So
     * unlike a true no-op, this succeeds immediately (no HTTP call,
     * nothing to fail) and hands back that documented map — wiring it
     * through the EXACT SAME "Refresh from SPX" button -> {ok, labels} ->
     * `spx_status_labels` setting flow QuadX's live refresh already uses
     * (the account's status-label refresh), so no shared code
     * needed to change for this courier to participate in it. See
     * statusLabels() below for the complementary half: the same map made available WITHOUT requiring that
     * button click first.
     */
    public function refreshStatusLabels(): array
    {
        return ['ok' => true, 'labels' => self::STATUS_LABELS];
    }

    /**
     * SPX status from code to its actual meaning: the documented vocabulary
     * made available WITHOUT requiring
     * the admin to click "Refresh from SPX" first. Not part of
     * Courier — no other courier has a static,
     * pre-known vocabulary to expose this way (QuadX's only comes from a
     * live call) — so callers consult it via method_exists(), the same
     * dispatch convention this class's own createShipperAccount() already
     * established for a capability that isn't universal. Consulted as a
     * fallback below the cached status labels (courier_status_labels) and
     * above the generic ucwords(raw code) fallback.
     */
    public function statusLabels(): array
    {
        return self::STATUS_LABELS;
    }

    /**
     * 5.1 Get Address file download link (get_address_download_url), cached
     * settings-style (Setting group=courier, key=spx_address_tree — same
     * on-demand-refresh pattern as `{courier}_status_labels`). SPX's own
     * address library is only exposed as an Excel export (no JSON tree), and
     * this codebase has no spreadsheet-parsing dependency, so this caches
     * the download link + fetch time for admin visibility/follow-up — it is
     * NOT consulted at booking: addresses are placed on the committed list
     * in database/data/spx (SpxLocations), recompiled from a newer xlsx with
     * ventaship:spx-locations-compile.
     *
     * @return array{ok:bool, url?:string, fetched_at?:string, error?:string}
     */
    public function refreshAddressTree(): array
    {
        if (!$this->client->hasAccountCredentials()) {
            return ['ok' => false, 'error' => 'User ID / User Secret not set. Cannot fetch the SPX address file.'];
        }

        try {
            $data = $this->client->getAddressDownloadUrl();
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Address file fetch failed: ' . $e->getMessage()];
        }

        $url = $data['address_download_url'] ?? null;
        if (!$url) {
            return ['ok' => false, 'error' => 'SPX did not return an address file URL.'];
        }

        $fetchedAt = now()->toIso8601String();
        $this->settings->write('spx_address_tree', json_encode(['url' => $url, 'fetched_at' => $fetchedAt]));

        return ['ok' => true, 'url' => $url, 'fetched_at' => $fetchedAt];
    }

    /** Every status code from api.md's order status table, in display order. */
    public function mappableStatuses(): array
    {
        return ['1001', '2001', '2006', '3001', '4001', '5001', '5002', '5003', '6001', '6002', '6003', '7001'];
    }

    /**
     * The per-account keys. The four credentials are stored once per
     * environment (spx_sandbox_* reaches SPX's UAT system, spx_production_*
     * its live one); the account's environment picks the set. On/off is the
     * account's status, so it is not a setting.
     *
     * spx_pouch_presets is `type: 'json'`: a JSON string of
     * `{label,length_cm,width_cm,height_cm,max_weight_kg}` objects (see
     * DEFAULT_POUCH_PRESETS above for the shape and defaults). It is checked
     * as JSON and stored compact; the admin screen shows it in a code box.
     */
    public function settingsSchema(): array
    {
        $credentials = [];
        foreach (['sandbox', 'production'] as $env) {
            $credentials[] = ['key' => "spx_{$env}_app_id", 'label' => 'App ID', 'type' => 'text', 'mode_scoped' => $env];
            $credentials[] = ['key' => "spx_{$env}_secret_key", 'label' => 'App Secret', 'type' => 'password', 'encrypted' => true, 'mode_scoped' => $env];
            $credentials[] = ['key' => "spx_{$env}_user_id", 'label' => 'User ID', 'type' => 'text', 'mode_scoped' => $env];
            $credentials[] = ['key' => "spx_{$env}_user_secret", 'label' => 'User Secret', 'type' => 'password', 'encrypted' => true, 'mode_scoped' => $env];
        }

        return [
            ...$credentials,
            [
                'key'   => 'spx_pouch_presets',
                'label' => 'Pouch presets',
                'type'  => 'json',
                'help'  => 'JSON array of {label, length_cm, width_cm, height_cm, max_weight_kg}. '
                    . 'Defaults: Small 25×20×10cm/3kg, Medium 35×25×15cm/5kg, Large 45×35×20cm/10kg.',
            ],
        ];
    }

    // --- Pending-creation resolution ---------------------------------------

    /**
     * Resolves an in-flight async creation (courier_status='creating') by
     * re-polling 1.5 Get Order Create Result with the batch_no stashed in
     * courier_meta at booking time. Called by courier:poll-status's
     * pending-creation pass — see Courier::
     * resolvePendingCreation()'s docblock for the full contract, including
     * why a permanent failure is handled here (flips courier_status to
     * 'creation_failed') rather than expressible through this method's
     * return type alone.
     */
    public function resolvePendingCreation(CourierOrder $order): ?array
    {
        if (!$this->client->hasAccountCredentials()) {
            return null;
        }

        $meta = $order->courier_meta ? (json_decode($order->courier_meta, true) ?: []) : [];
        $batchNo = $meta['batch_no'] ?? null;
        if (!$batchNo) {
            return null;
        }

        try {
            $resultData = $this->client->getOrderCreateResult((int) $batchNo);
        } catch (\Throwable $e) {
            return null; // transient — the next scheduled poll tries again.
        }

        if (!is_array($resultData) || !in_array((int) ($resultData['task_status'] ?? 0), self::CREATE_RESULT_SETTLED, true)) {
            return null; // still New/Waiting/Running.
        }

        $outcome = $this->outcomeFromCreateResult($resultData);
        if (!$outcome['ok'] && ($outcome['code'] ?? null) === 13103) {
            // SPX already has this order_id from an earlier attempt: that order is this shipment's booking.
            $answer = $this->alreadyCreated($order, [], (int) $batchNo);
            if (($answer['success'] ?? false) !== true) {
                return ['outcome_unknown' => true, 'attention_reason' => $answer['attention_reason']];
            }

            return [
                'tracking_number' => $answer['tracking_number'],
                'meta'            => $answer['meta'],
                'fee'             => $answer['fee'],
            ];
        }
        if (!$outcome['ok']) {
            $meta['creation_failed'] = true;
            $meta['creation_error']  = $outcome['error'];
            Log::warning('SPX pending shipment creation failed', [
                'order' => $order->id, 'batch_no' => $batchNo, 'reason' => $outcome['error'],
            ]);
            // The caller writes it: the courier code holds no order of its own.
            return ['courier_status' => 'creation_failed', 'meta' => $meta];
        }

        $tracking = $outcome['order']['tracking_no'] ?? null;
        if (!$tracking) {
            return null;
        }

        // The same booked data createShipment() stores when SPX settles at once.
        return [
            'tracking_number' => (string) $tracking,
            'meta'            => self::bookedMeta($outcome['order']),
            'fee'             => Money::decimal($outcome['order']['estimated_shipping_fee'] ?? null),
        ];
    }

    /**
     * What a settled booking keeps in meta, whichever path settled it: SPX's own
     * 1.5 order entry verbatim (tracking_link, sort codes, the fee breakdown),
     * its estimated fee and remote-area fee as decimal strings, and the
     * delivery estimate when SPX gave one.
     *
     * @param array<string, mixed> $order one entry of 1.5's data.orders
     * @return array<string, mixed>
     */
    private static function bookedMeta(array $order): array
    {
        return array_filter([
            'booked_response'        => $order,
            'estimated_shipping_fee' => Money::decimal($order['estimated_shipping_fee'] ?? null),
            // Undocumented (seen in the real 1.5 answer): absent means not known, never 0.
            'remote_area_fee'        => Money::decimal($order['remote_area_fee'] ?? null),
            'tracking_links'         => self::deliveryLink($order['tracking_link'] ?? $order['tracking_no_link'] ?? null),
            'edd'                    => self::edd($order['edd_min'] ?? $order['edd_info']['edd_min'] ?? null, $order['edd_max'] ?? $order['edd_info']['edd_max'] ?? null, 'booking'),
        ], fn ($v) => $v !== null);
    }

    /** @return array{delivery:string}|null an http(s) tracking link as trackingLinks() reads it */
    private static function deliveryLink(mixed $url): ?array
    {
        return self::url($url) !== null ? ['delivery' => self::url($url)] : null;
    }

    /** An http(s) URL of sane length, or null. */
    private static function url(mixed $url): ?string
    {
        if (!is_string($url)) {
            return null;
        }
        $url = trim($url);
        if ($url === '' || strlen($url) > 2048 || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) ? $url : null;
    }

    /**
     * SPX's delivery estimate, kept as SPX gives it (the docs call it days in
     * 1.2 and leave the unit unsaid in 4.1). Both zero is SPX's "none" (its own
     * 1.2 example), so it is not stored.
     *
     * @return array{min:?int, max:?int, source:string}|null
     */
    private static function edd(mixed $min, mixed $max, string $source): ?array
    {
        $min = is_numeric($min) ? (int) $min : null;
        $max = is_numeric($max) ? (int) $max : null;
        if (($min === null && $max === null) || (($min ?? 0) === 0 && ($max ?? 0) === 0)) {
            return null;
        }

        return ['min' => $min, 'max' => $max, 'source' => $source];
    }

    /**
     * Proof URLs from a list SPX sends, http(s) only, at most 20, duplicates dropped.
     *
     * @return list<string>|null
     */
    private static function urls(mixed $list): ?array
    {
        if (!is_array($list)) {
            return null;
        }
        $urls = [];
        foreach ($list as $item) {
            if (($url = self::url($item)) !== null && !in_array($url, $urls, true)) {
                $urls[] = $url;
            }
            if (count($urls) >= 20) {
                break;
            }
        }

        return $urls === [] ? null : $urls;
    }

    /** A short display text (a driver's name or phone), trimmed and capped; null when empty. */
    private static function shortText(mixed $value, int $max): ?string
    {
        if (!is_string($value) && !is_int($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    // --- Payload builders ---------------------------------------------------

    /**
     * Why SPX would refuse this parcel, checked before anything is sent:
     * a weight under 50 kg, sides of at most 150 cm, and COD in whole pesos
     * up to ₱50,000 (guide.md:600-604; api.md cod_amount).
     */
    private function limitProblem(CourierOrder $order, array $opts): ?string
    {
        $dims = $this->resolveParcelDims($opts);
        if (!$dims['ok']) {
            return $dims['error'];
        }

        $weight = self::typedWeight($order, $opts);
        if ($weight === null) {
            return 'Enter the parcel weight.';
        }
        if (bccomp(self::decimal($weight), self::MAX_WEIGHT_KG, 3) >= 0) {
            return 'SPX weight must be under 50 kg.';
        }

        foreach (['length_cm', 'width_cm', 'height_cm'] as $side) {
            if (bccomp(self::decimal($dims['dims'][$side]), self::MAX_SIDE_CM, 3) > 0) {
                return "SPX sides can't exceed 150 cm.";
            }
        }

        // SPX's cod_amount is whole pesos: rounding would collect a different amount than the shipment says.
        $collect = self::decimal((float) $order->collectAmount());
        if (bccomp($collect, bcadd($collect, '0', 0), 2) !== 0) {
            return 'SPX collects whole pesos only.';
        }
        if (bccomp($collect, self::MAX_COD, 2) > 0) {
            return 'SPX COD must be whole pesos up to ₱50,000.';
        }

        return null;
    }

    /**
     * The parcel weight the shipment gives: the typed parcel.weight_kg, else
     * the sum of its items' weights. Null when neither is known.
     */
    private static function typedWeight(CourierOrder $order, array $opts): ?float
    {
        $typed = $opts['weight_kg'] ?? null;
        if (is_numeric($typed) && (float) $typed > 0) {
            return round((float) $typed, 3);
        }

        return OrderWeight::kg($order);
    }

    /** A number as a plain decimal string for bcmath (no exponent). */
    private static function decimal(float|int|string $n): string
    {
        return rtrim(rtrim(sprintf('%.3F', (float) $n), '0'), '.') ?: '0';
    }

    /**
     * `kind` (a ServiceabilityVerdict kind) is set when SPX's location list
     * itself rules the address out, so checkServiceability() can say so
     * without asking SPX.
     *
     * @return array{ok:bool, payload?:array, error?:string, kind?:string, code?:string}
     */
    private function buildOrderPayload(CourierOrder $order, array $opts): array
    {
        $sender = $this->senderInfo($opts);
        if (!$sender['ok']) {
            return ['ok' => false, 'error' => $sender['error']] + array_intersect_key($sender, ['kind' => 1, 'code' => 1]);
        }

        $deliver = $this->deliverInfo($order);
        if (!$deliver['ok']) {
            return ['ok' => false, 'error' => $deliver['error']] + array_intersect_key($deliver, ['kind' => 1, 'code' => 1]);
        }

        $fulfillment = $this->fulfillmentInfo($order, $opts);
        // A pickup shipment is checked as collected even before it has a window (a fee check).
        $collected = $fulfillment['collect_type'] === 1 || ($opts['method'] ?? null) === 'pickup';
        if (($refusal = self::supportRefusal($sender['flags'], $deliver['flags'], (bool) $order->cod, $collected)) !== null) {
            return ['ok' => false] + $refusal;
        }

        $dims = $this->resolveParcelDims($opts);
        if (!$dims['ok']) {
            return ['ok' => false, 'error' => $dims['error']];
        }

        // The real weight. A preset's maximum stands in only where no weight
        // can be known yet (the serviceability from-price before a parcel is
        // chosen); createShipment() refuses a booking without one.
        $weight = self::typedWeight($order, $opts) ?? $dims['dims']['max_weight_kg'];
        if ($weight === null) {
            return ['ok' => false, 'error' => 'Enter the parcel weight.'];
        }
        $dims['dims']['weight_kg'] = (float) $weight;

        return ['ok' => true, 'payload' => [
            'order_id'         => (string) $order->reference,
            'base_info'        => ['service_type' => 1],
            'sender_info'      => $sender['info'],
            'fulfillment_info' => $fulfillment,
            'deliver_info'     => $deliver['info'],
            'parcel_info'      => $this->parcelInfo($order, $dims['dims'], $opts),
        ]];
    }

    /**
     * Sender = the pickup point the client sent with the shipment
     * ($opts['pickup'], see PickupOrigin::from()), the same origin QuadX and
     * LBC read.
     *
     * SPX's address levels are not PSGC's:
     * state = island group, city = province, district = city/municipality
     * (Manila: its sub-district), street = barangay. The stored PSGC address
     * is placed on a row of SPX's own location list (SpxLocations) and sent
     * exactly as SPX spells it; an address SPX does not list is refused here,
     * never sent as a guess. detail_address is the unit/building text.
     *
     * @return array{ok:bool, info?:array, flags?:string, error?:string, kind?:string}
     */
    private function senderInfo(array $opts = []): array
    {
        $pickup = PickupOrigin::from($opts);

        $state    = (string) ($pickup->state ?? '');
        $city     = (string) ($pickup->city ?? '');
        $district = (string) ($pickup->district ?? '');
        $barangay = (string) ($pickup->barangay ?? '');
        $address  = SpxFieldLimits::detailAddress($pickup->address_1 ?? null, $pickup->address_2 ?? null);
        $postCode = trim((string) ($pickup->postcode ?? ''));
        $name     = trim((string) ($pickup->contact_name ?? '')) ?: 'Sender';
        $phone    = $this->normalizePhPhone($pickup->contact_phone ?? '');

        if ($postCode === '') {
            $postCode = (string) app(PhAddress::class)->postcodeFor([
                'state' => $state, 'city' => $city, 'state_code' => $pickup->state_code ?? null,
                'city_code' => $pickup->city_code ?? null, 'barangay_code' => $pickup->barangay_code ?? null,
            ]);
        }
        if (($missing = self::missingField('sender', $state, $city, $barangay, $address, $postCode, $phone)) !== null) {
            return ['ok' => false] + $missing;
        }

        $spx = $this->locations()->resolve([
            'state' => $state, 'city' => $city, 'district' => $district, 'barangay' => $barangay,
            'codes' => ['barangay' => (string) ($pickup->barangay_code ?? '')],
        ]);
        if (!$spx['ok']) {
            return ['ok' => false, 'error' => self::unlistedMessage($spx['missing'], 'sender'), 'kind' => ServiceabilityVerdict::KIND_ORIGIN, 'code' => 'spx_unlisted_pickup_' . $spx['missing']];
        }
        $at = $spx['location'];

        return ['ok' => true, 'flags' => $spx['flags'], 'info' => [
            'sender_state'          => $at['state'],
            'sender_city'           => $at['city'],
            'sender_district'       => $at['district'],
            'sender_street'         => $at['street'],
            'sender_post_code'      => $postCode,
            // Validation refuses longer values (SpxFieldLimits); the cut only guards data stored before it.
            'sender_name'           => mb_substr($name, 0, SpxFieldLimits::NAME_MAX),
            'sender_phone'          => $phone,
            'sender_detail_address' => mb_substr($address, 0, SpxFieldLimits::DETAIL_ADDRESS_MAX),
        ]];
    }

    /**
     * Deliver = the order's own shipping address, placed on SPX's location
     * list exactly as senderInfo() places the pickup point. An order with no
     * barangay, or one SPX does not list, is refused rather than sent with a
     * stand-in value.
     *
     * @return array{ok:bool, info?:array, flags?:string, error?:string, kind?:string}
     */
    private function deliverInfo(CourierOrder $order): array
    {
        $state = trim((string) $order->shipping_state);
        $city  = trim((string) $order->shipping_city);
        $district = trim((string) $order->shipping_district);
        $barangay = trim((string) $order->shipping_barangay);
        $postCode = trim((string) $order->shipping_postcode);
        $address  = SpxFieldLimits::detailAddress($order->shipping_address_1, $order->shipping_address_2);
        $phone    = $this->normalizePhPhone($order->shipping_phone ?: $order->phone);

        if ($postCode === '') {
            $postCode = (string) app(PhAddress::class)->postcodeFor([
                'state' => $state, 'city' => $city, 'state_code' => $order->shipping_state_code ?? null,
                'city_code' => $order->shipping_city_code ?? null, 'barangay_code' => $order->shipping_barangay_code ?? null,
            ]);
        }
        // The customer's street address is optional here (the barangay stands in), so it is passed as present.
        if (($missing = self::missingField('customer', $state, $city, $barangay, 'x', $postCode, $phone)) !== null) {
            return ['ok' => false] + $missing;
        }

        $spx = $this->locations()->resolve([
            'state' => $state, 'city' => $city, 'district' => $district, 'barangay' => $barangay,
            'codes' => ['barangay' => (string) ($order->shipping_barangay_code ?? '')],
        ]);
        if (!$spx['ok']) {
            return ['ok' => false, 'error' => self::unlistedMessage($spx['missing'], 'customer'), 'kind' => ServiceabilityVerdict::KIND_DESTINATION, 'code' => 'spx_unlisted_' . $spx['missing']];
        }
        $at = $spx['location'];
        if ($address === '') {
            // detail_address mandatory; fall back to whatever granular text
            // we do have rather than sending an empty string.
            $address = $barangay;
        }

        $info = [
            'deliver_state'          => $at['state'],
            'deliver_city'           => $at['city'],
            'deliver_district'       => $at['district'],
            'deliver_street'         => $at['street'],
            'deliver_post_code'      => $postCode,
            // Validation refuses longer values (SpxFieldLimits); the cut only guards data stored before it.
            'deliver_name'           => mb_substr(SpxFieldLimits::fullName($order->shipping_first_name, $order->shipping_last_name) ?: 'Customer', 0, SpxFieldLimits::NAME_MAX),
            'deliver_phone'          => $phone,
            'deliver_detail_address' => mb_substr($address, 0, SpxFieldLimits::DETAIL_ADDRESS_MAX),
        ];
        // deliver_instruction is optional (api.md §1.4: N, "receiver's
        // remark, max length 256") — omit entirely rather than sending a
        // JSON null SPX never asked for. It carries the buyer's message to
        // the courier when one exists (core's courier_comment column).
        $instruction = trim((string) $order->courier_comment);
        if ($instruction !== '') {
            $info['deliver_instruction'] = mb_substr($instruction, 0, SpxFieldLimits::INSTRUCTION_MAX);
        }

        return ['ok' => true, 'info' => $info, 'flags' => $spx['flags']];
    }

    private function locations(): SpxLocations
    {
        return app(SpxLocations::class);
    }

    /**
     * The first thing our own data lacks, naming whose address it is; null when complete.
     *
     * @return array{error:string, code:string}|null
     */
    private static function missingField(string $side, string $state, string $city, string $barangay, string $address, string $postCode, ?string $phone): ?array
    {
        $whose = $side === 'sender' ? "The sender's" : "The customer's";
        $prefix = $side === 'sender' ? 'pickup_' : '';
        foreach (['province' => $state, 'city' => $city, 'barangay' => $barangay, 'address' => $address, 'postcode' => $postCode] as $field => $value) {
            if ($value === '') {
                return ['error' => "{$whose} {$field} is missing.", 'code' => "missing_{$prefix}{$field}"];
            }
        }
        if ($phone === null) {
            return ['error' => "{$whose} phone isn't a valid PH mobile number.", 'code' => "missing_{$prefix}phone"];
        }

        return null;
    }

    /** "SPX doesn't list the customer's barangay." — the level SpxLocations could not place, and whose address. */
    private static function unlistedMessage(string $level, string $whose): string
    {
        return "SPX doesn't list the {$whose}'s {$level}.";
    }

    /**
     * The SPX location flags this booking needs: delivery (and COD delivery
     * when COD) at the customer's barangay, pickup (and COD pickup when COD)
     * at the sender's when the parcel is collected rather than dropped off.
     *
     * @return array{error:string, kind:string, code:string}|null
     */
    private static function supportRefusal(string $senderFlags, string $deliverFlags, bool $cod, bool $pickup): ?array
    {
        $checks = [
            [$deliverFlags, SpxLocations::DELIVER, true, "SPX doesn't deliver to this barangay.", ServiceabilityVerdict::KIND_DESTINATION, 'spx_no_delivery'],
            [$deliverFlags, SpxLocations::COD_DELIVER, $cod, "SPX doesn't collect COD in this barangay.", ServiceabilityVerdict::KIND_COD, 'spx_no_cod'],
            [$senderFlags, SpxLocations::PICKUP, $pickup, "SPX doesn't pick up from the sender's barangay.", ServiceabilityVerdict::KIND_ORIGIN, 'spx_no_pickup'],
            [$senderFlags, SpxLocations::COD_PICKUP, $pickup && $cod, "SPX doesn't pick up COD parcels from the sender's barangay.", ServiceabilityVerdict::KIND_ORIGIN, 'spx_no_cod_pickup'],
        ];
        foreach ($checks as [$flags, $flag, $needed, $error, $kind, $code]) {
            if ($needed && !SpxLocations::supports($flags, $flag)) {
                return ['error' => $error, 'kind' => $kind, 'code' => $code];
            }
        }

        return null;
    }

    /**
     * Normalizes a raw phone number to SPX's mandatory PH format (api.md
     * §1.4: "11 digits starting with 0, or 12 digits starting with 63" for
     * both sender_phone and deliver_phone). Strips everything but digits
     * first, so the common raw shapes all
     * resolve: `+63 917 123 4567` / `639171234567` (already 12 digits
     * starting with 63) pass through as-is; a bare 10-digit mobile with no
     * leading 0 (`9171234567`) gets a `0` prepended to become the 11-digit
     * form. Returns null when nothing salvageable remains (empty, too
     * short, garbage) — every caller turns that into a clear pre-flight
     * failure rather than sending SPX a value it's guaranteed to reject.
     *
     * The rule itself moved to Support\PhPhone so the pickup-address form
     * validates a mobile number by exactly the rule this method will later
     * build the payload with. Behaviour is unchanged; this stays as the
     * name every call site in this class already uses.
     */
    private function normalizePhPhone(?string $raw): ?string
    {
        return PhPhone::normalize($raw);
    }

    /**
     * @return array{payment_role:int, cod_collection:int, cod_amount?:int, collect_type:int, pickup_time?:int, pickup_time_range_id?:int, pickup_time_range?:string}
     */
    private function fulfillmentInfo(CourierOrder $order, array $opts): array
    {
        // payment_role: 1 sender pay, 2 receiver pay — the who-pays choice
        // from the booking form's shipping_payment select
        // (getShippingPaymentOptions()).
        $paymentRole = ($opts['shipping_payment'] ?? null) === 'receiver_pay' ? 2 : 1;

        $isCod = (bool) $order->cod;
        $info = [
            'payment_role'   => $paymentRole,
            'cod_collection' => $isCod ? 1 : 0,
        ];
        if ($isCod) {
            // What the rider collects: the shipment's cod_amount, never its order total. SPX takes whole pesos.
            $info['cod_amount'] = (int) round((float) $order->collectAmount());
        }

        // collect_type: 1 pickup, 2 drop-off. A pickup sends its window
        // (createShipment() always has one for a pickup: the one asked for or
        // the earliest SPX offers). A drop-off, or a fee check that has no
        // window yet, is collect_type 2.
        $slot = ($opts['method'] ?? null) === 'dropoff' ? null : self::decodeSlot($opts['pickup_slot'] ?? null);
        if ($slot !== null) {
            $info['collect_type']         = 1;
            $info['pickup_time']          = $slot['pickup_time'];
            $info['pickup_time_range_id'] = $slot['pickup_time_range_id'];
            $info['pickup_time_range']    = $slot['pickup_time_range'];
        } else {
            $info['collect_type'] = 2;
        }

        return $info;
    }

    /**
     * A pickup window as getPickupSlots() hands it out (JSON with pickup_time,
     * pickup_time_range_id and pickup_time_range); null for anything else.
     *
     * @return array{pickup_time:int, pickup_time_range_id:int, pickup_time_range:string}|null
     */
    private static function decodeSlot(mixed $raw): ?array
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !is_numeric($decoded['pickup_time'] ?? null) || !is_numeric($decoded['pickup_time_range_id'] ?? null)
            || !is_string($decoded['pickup_time_range'] ?? null)) {
            return null;
        }
        return [
            'pickup_time'          => (int) $decoded['pickup_time'],
            'pickup_time_range_id' => (int) $decoded['pickup_time_range_id'],
            'pickup_time_range'    => $decoded['pickup_time_range'],
        ];
    }

    private function parcelInfo(CourierOrder $order, array $dims, array $opts): array
    {
        $products = $order->products;
        $qty = 0;
        foreach ($products as $p) {
            $qty += (int) $p->quantity;
        }
        $qty = $qty > 0 ? $qty : 1;

        $firstName = $products->first()?->name;
        $itemName = $firstName ? Str::limit($firstName, 60, '') : ('Order ' . $order->reference);

        return [
            'parcel_weight'         => $dims['weight_kg'],
            'parcel_length'         => $dims['length_cm'],
            'parcel_width'          => $dims['width_cm'],
            'parcel_height'         => $dims['height_cm'],
            'parcel_item_name'      => $itemName,
            'parcel_item_quantity'  => $qty,
            // SPX insures via express_insured_value — mirrors QuadX's
            // declared_value insurance hazard (omitting it risks a silent
            // over/under-insure default): explicit 0 unless the admin
            // opted in via the same "insure" checkbox QuadX's form uses.
            'express_insured_value' => (bool) ($opts['insure'] ?? false) ? $order->insuredValue() : 0,
            'parcel_item_type'      => self::itemType($opts),
        ];
    }

    /** The shipment's parcel.category when SPX lists it verbatim, else General merchandise. */
    private static function itemType(array $opts): string
    {
        $category = $opts['category'] ?? null;

        return in_array($category, self::PARCEL_ITEM_TYPES, true) ? $category : self::PARCEL_ITEM_TYPE;
    }

    /**
     * Resolves parcel dims from the chosen pouch preset, or explicit custom
     * dimensions (forward-compatible with the interface's own $opts shape —
     * weight_kg/length_cm/width_cm/height_cm are already documented there
     * for every courier). getParcelOptions() advertises presets by a slug
     * value plus a 'custom' sentinel — this is the single place that slug
     * resolves back into real dims, so the two stay in lockstep.
     *
     * Presets supply the dimensions only; max_weight_kg is the preset's
     * ceiling (null for custom), never the weight booked.
     *
     * @return array{ok:bool, dims?:array{length_cm:float,width_cm:float,height_cm:float,max_weight_kg:?float}, error?:string}
     */
    private function resolveParcelDims(array $opts): array
    {
        $key = $opts['parcel'] ?? null;

        if ($key === 'custom') {
            $length = $opts['length_cm'] ?? null;
            $width  = $opts['width_cm'] ?? null;
            $height = $opts['height_cm'] ?? null;
            if (!is_numeric($length) || !is_numeric($width) || !is_numeric($height)
                || (float) $length <= 0 || (float) $width <= 0 || (float) $height <= 0) {
                return ['ok' => false, 'error' => 'Enter the parcel size.'];
            }
            return ['ok' => true, 'dims' => [
                'length_cm' => (float) $length, 'width_cm' => (float) $width,
                'height_cm' => (float) $height, 'max_weight_kg' => null,
            ]];
        }

        foreach ($this->pouchPresets() as $preset) {
            if (Str::slug((string) ($preset['label'] ?? '')) === $key) {
                return ['ok' => true, 'dims' => [
                    'length_cm' => (float) $preset['length_cm'], 'width_cm' => (float) $preset['width_cm'],
                    'height_cm' => (float) $preset['height_cm'], 'max_weight_kg' => (float) $preset['max_weight_kg'],
                ]];
            }
        }

        // Unknown/omitted parcel value (stale option, or an order switched
        // onto SPX after another courier's parcel value was selected) —
        // fall back to the first configured preset rather than failing a
        // whole booking over a cosmetic mismatch.
        $fallback = $this->pouchPresets()[0] ?? self::DEFAULT_POUCH_PRESETS[0];
        return ['ok' => true, 'dims' => [
            'length_cm' => (float) $fallback['length_cm'], 'width_cm' => (float) $fallback['width_cm'],
            'height_cm' => (float) $fallback['height_cm'], 'max_weight_kg' => (float) $fallback['max_weight_kg'],
        ]];
    }

    private function pouchPresets(): array
    {
        $raw = $this->settings->read('spx_pouch_presets');
        $decoded = $raw ? json_decode($raw, true) : null;
        return (is_array($decoded) && $decoded !== []) ? $decoded : self::DEFAULT_POUCH_PRESETS;
    }

    // --- Async create polling -------------------------------------------

    /** data.task_status values (api.md §1.5) that mean "stop polling, the batch is settled" — 4 Done, 5 Failed. Both New(1)/Waiting(2)/Running(3) keep polling. */
    private const CREATE_RESULT_SETTLED = [4, 5];

    /**
     * Polls 1.5 Get Order Create Result up to ~15s total (backoff between
     * attempts: 1s, 2s, 3s, 4s, 5s — 6 attempts, 5 waits), bounded so an
     * admin clicking "Book" never hangs indefinitely on SPX's async create.
     * Returns the raw result data once task_status settles (4 Done or 5
     * Failed — see CREATE_RESULT_SETTLED; a Failed batch is over just as
     * definitively as a Done one, so there is nothing to gain by continuing
     * to poll it for the rest of the window), or null if the window elapses
     * first (still New/Waiting/Running) — createShipment() persists
     * 'creating' in that case and courier:poll-status finishes the job
     * later.
     */
    private function pollCreateResult(int $batchNo): ?array
    {
        $backoffMs = [1000, 2000, 3000, 4000, 5000];
        $attempts = count($backoffMs) + 1;

        for ($i = 0; $i < $attempts; $i++) {
            try {
                $result = $this->client->getOrderCreateResult($batchNo);
            } catch (\Throwable $e) {
                $result = null;
            }

            if (is_array($result) && in_array((int) ($result['task_status'] ?? 0), self::CREATE_RESULT_SETTLED, true)) {
                return $result;
            }

            if ($i < $attempts - 1) {
                ($this->sleeper)($backoffMs[$i]);
            }
        }

        return null;
    }

    /**
     * Our sentence for the create failures SPX's guide lists as the most
     * common (portal/guide/12, meanings from guide/08), so the operator reads
     * what to fix. Any other code is told in SPX's own words.
     */
    private const CREATE_FAILURES = [
        10001 => 'The parcel is too heavy for SPX.',
        10003 => 'The COD amount is more than SPX collects.',
        10018 => "SPX doesn't serve the pickup address.",
        10019 => "SPX doesn't serve the delivery address.",
        10021 => 'The delivery address is too far from the pickup address for SPX.',
        10022 => 'The delivery address is too close to the pickup address for SPX.',
        10077 => "SPX couldn't place the pickup address on its map.",
        10078 => "SPX couldn't place the delivery address on its map.",
        10080 => "The pickup slot is past SPX's cutoff. Choose another pickup date.",
        10083 => 'The parcel is too heavy for SPX (actual or volumetric weight).',
        10084 => 'The parcel is too long for SPX.',
        10085 => 'The parcel is too wide for SPX.',
        10086 => 'The parcel is too tall for SPX.',
    ];

    /**
     * One fail_list entry (1.5) as the shipment's error: a human sentence
     * first, then SPX's code and its specific reason (debug_msg) in brackets,
     * e.g. "The pickup slot is past SPX's cutoff. Choose another pickup date.
     * (SPX 10080: now_time over cutoff_time)".
     *
     * @param array<string, mixed> $fail
     */
    private static function createFailureText(array $fail): string
    {
        $code = is_numeric($fail['ret_code'] ?? null) ? (int) $fail['ret_code'] : null;
        $message = self::cleanText($fail['message'] ?? null);
        $debug = self::cleanText($fail['debug_msg'] ?? null);

        $sentence = ($code !== null ? (self::CREATE_FAILURES[$code] ?? null) : null)
            ?? ($message ?? 'SPX could not create this shipment.');
        $detail = $debug ?? (isset(self::CREATE_FAILURES[$code ?? -1]) ? $message : null);
        if ($detail !== null && $detail === $sentence) {
            $detail = null;
        }

        $bracket = match (true) {
            $code !== null && $detail !== null => "SPX {$code}: {$detail}",
            $code !== null => "SPX {$code}",
            $detail !== null => "SPX: {$detail}",
            default => null,
        };

        return $bracket === null ? $sentence : "{$sentence} ({$bracket})";
    }

    /** SPX's text on one line, at most 300 characters; null when empty. */
    private static function cleanText(mixed $text): ?string
    {
        if (!is_string($text)) {
            return null;
        }
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return $text === '' ? null : mb_substr($text, 0, 300);
    }

    /**
     * "Be careful: if all orders failed to be created, the task status is
     * still Done" (api.md §1.5) — success is NEVER inferred from task_status
     * alone, only from fail_list/fail_count. An
     * explicit task_status 5 (Failed) is treated the same way — it's simply
     * another way this batch can be settled-but-unsuccessful, folded into
     * the same "no usable order" branch below via $failed.
     *
     * @return array{ok:bool, order?:array, error?:string, code?:?int} `code`: the first fail_list entry's ret_code
     */
    private function outcomeFromCreateResult(array $resultData): array
    {
        $taskFailed = (int) ($resultData['task_status'] ?? 0) === 5;
        $failCount  = (int) ($resultData['fail_count'] ?? 0);
        $failList   = $resultData['fail_list'] ?? [];
        $orders     = $resultData['orders'] ?? [];

        if ($taskFailed || $failCount > 0 || !empty($failList) || empty($orders)) {
            $first = is_array($failList[0] ?? null) ? $failList[0] : null;
            $reason = $first !== null
                ? self::createFailureText($first)
                : ($taskFailed
                    ? ('SPX marked this batch as Failed' . (!empty($resultData['description']) ? ": {$resultData['description']}" : '.'))
                    : 'SPX could not create this shipment.');
            $code = $first !== null && is_numeric($first['ret_code'] ?? null) ? (int) $first['ret_code'] : null;

            return ['ok' => false, 'error' => $reason, 'code' => $code];
        }

        $created = $orders[0];
        if (empty($created['tracking_no'])) {
            return ['ok' => false, 'error' => 'SPX reported success but returned no tracking number.'];
        }

        return ['ok' => true, 'order' => $created];
    }
}
