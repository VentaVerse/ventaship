<?php

namespace App\Couriers\Spx;

use App\Couriers\AccountSettings;
use App\Couriers\CourierOrder;
use App\Couriers\Contracts\Courier;
use App\Couriers\PickupOrigin;
use App\Couriers\PickupPoint;
use App\Couriers\ServiceabilityService;
use App\Support\PhAddress;
use App\Couriers\Support\OrderWeight;
use App\Couriers\Support\PhPhone;
use App\Couriers\Support\ServiceabilityVerdict;
use App\Models\CourierAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * SPX (Shopee Xpress) courier — Task T2 of the fulfillment-workflow-v2 + SPX
 * plan (`docs/superpowers/plans/2026-08-08-spx-courier.md`). Fills in every
 * method Task T1 left as a deliberate stub: createShipment/cancelShipment/
 * getTracking, the status-vocabulary/phase methods, the option lists, and
 * the v2 estimateFee()/getPickupSlots() methods. Ground truth for every shape
 * is docs/spx/api.md (verbatim endpoint tables) + docs/spx/integration-brief.md
 * — see each method's docblock for which section it implements.
 *
 * Task T3 additions: printLabel() (1.6 Get AWB V2 — download+store the
 * perishable label PDF, serve it through this app's own authenticated
 * route), parseWebhook() + verifyWebhookSignature() (4.1 Tracking Web Hook —
 * signed fast-ack dispatch, see CourierWebhookController's SPX branch and
 * App\Couriers\Jobs\ProcessCourierWebhookJob).
 *
 * Load-bearing decisions made in THIS task (documented in the T2 report too):
 *  - Sender address reuses the module-wide pickup origin rather than a new
 *    spx_sender_* settings block, since QuadX already shares that exact
 *    origin — one pickup address serves every courier. That origin is now a
 *    row in courier_fulfillment_pickup_addresses (a store may keep several
 *    and choose one per booking); it used to be the eight flat
 *    `settings(group=shipping, key=pickup_*)` rows, which are retired.
 *  - Sender and deliver addresses are placed on SPX's own location list
 *    (SpxLocations, compiled from SPX's xlsx by ventaship:spx-locations-compile):
 *    SPX's state/city/district/street are island group/province/city/barangay,
 *    not PSGC's levels, and an address SPX does not list is refused before
 *    anything is sent. refreshAddressTree() still caches SPX's 5.1 download
 *    link for an admin to fetch a newer list.
 *  - collect_type defaults to 2 (drop-off) because the current booking form
 *    doesn't collect a pickup-slot choice yet; getPickupSlots() is fully
 *    implemented and createShipment() already switches to collect_type=1
 *    the moment a future UI passes back $opts['pickup_slot'].
 *  - Actual post-booking fee (2.2 batch_get_asf) is fetched inside every
 *    getTracking() call, but PERSISTED at exactly one place:
 *    PollCourierStatus's scheduled loop (courier:poll-status, a T2 file),
 *    which writes it into courier_meta.actual_fee alongside links/timeline —
 *    see that command's docblock and getTracking()'s own. The manual
 *    per-order Refresh button (FulfillmentController::track(), NOT a T2
 *    file) still receives fee_info back but doesn't persist it yet — a
 *    documented follow-up, not silently dropped by design.
 */
class SpxFulfillment implements Courier
{
    /**
     * Sensible default PH pouch dimensions (T1 placeholder, editable via the
     * spx_pouch_presets setting — see settingsSchema()). Well inside SPX's
     * documented PH parcel limits (integration-brief.md §8: weight < 50kg,
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
     * SPX's failures, classified by GROUP rather than one code at a time
     * (owner ruling, 2026-08-12), so a code nobody has seen yet still lands
     * somewhere sensible instead of falling through as a mystery.
     *
     * These four are REFUSALS of this shipment: the card goes inert and the
     * modal writes its own sentence for the group, naming the destination.
     * Codes are the owner's own grouping of docs/spx/guide.md's error tables.
     *
     * Two of them are deliberately NOT "does not deliver here":
     *  - `cod` is an address SPX will happily deliver to, just not collecting
     *    cash at, which matters because this store sells heavily on COD.
     *  - `postcode` is a field on the address, and naming it is what lets an
     *    operator fix it in ten seconds.
     */
    private const REFUSAL_GROUPS = [
        // Destination not covered. 10062 and 10066 are guide.md codes outside
        // the owner's table, added here because they say the same thing:
        // "no route code for this address", "basic serviceable area not
        // configured".
        ServiceabilityVerdict::KIND_DESTINATION => [10016, 10017, 10019, 10023, 10026, 10033, 10062, 10066],
        // Origin not covered. 10065 ("pickup and delivery are not supported")
        // is guide.md's, and lands here because a pickup address SPX will not
        // collect from blocks every order, not just this one.
        ServiceabilityVerdict::KIND_ORIGIN      => [10014, 10015, 10018, 10025, 10054, 10065],
        // COD not supported at this address.
        ServiceabilityVerdict::KIND_COD         => [10029, 10030, 10031, 10032, 10043],
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
        ServiceabilityVerdict::KIND_PARCEL     => [10001, 10003, 10004, 10011, 10050, 10064],
        ServiceabilityVerdict::KIND_SLOT       => [10013, 10024, 10038, 10039, 10040, 10056],
        ServiceabilityVerdict::KIND_THEIR_SIDE => [10020, 10044, 10045, 10049, 10052],
    ];

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

    /** api.md §"Order status codes" (also integration-brief.md §5's table), bucketed for statusPhase()/terminalStatuses()/cancelableStatuses(). */
    private const DELIVERED = ['4001'];
    private const CANCELLED = ['7001'];
    /** Coming back to the seller — in progress (6001) or completed (6003), mirrors QuadX's 'returned' also living in the 'returning' bucket, not 'delivered'. */
    private const RETURNING_STATUSES = ['6001', '6003'];
    /** Exceptions that need the seller's attention. 3001/5001 are pre-pickup (legacy step 1); 5002/5003/6002 only happen once SPX already has the parcel (step 2). */
    private const FAILED_STATUSES = ['3001', '5001', '5002', '5003', '6002'];
    private const FAILED_PRE_PICKUP = ['3001', '5001'];
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

    /** Configured spx_pouch_presets (or DEFAULT_POUCH_PRESETS — T1 carry-forward note) plus a custom-dims escape hatch. */
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
        // Carry-forward note (b) from T1's review: the booking gate must
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

        $built = $this->buildOrderPayload($order, $opts);
        if (!$built['ok']) {
            return ['success' => false, 'error' => $built['error']];
        }

        if (($problem = $this->limitProblem($order, $opts)) !== null) {
            return ['success' => false, 'error' => $problem];
        }
        $orderPayload = $built['payload'];

        try {
            $createData = $this->client->createOrders([$orderPayload]);
        } catch (SpxApiException $e) {
            // A clear refusal, unless it came with a server error: then SPX may have taken the order anyway.
            return array_filter([
                'success' => false,
                'error' => "SPX booking failed: {$e->getMessage()} (ret_code {$e->retCode})",
                'outcome_unknown' => $e->httpStatus >= 500 ?: null,
            ], fn ($v) => $v !== null);
        } catch (\Throwable $e) {
            // Transport failure, timeout or a non-envelope answer (a 5xx page): the order may exist at SPX.
            return ['success' => false, 'error' => 'SPX booking failed: ' . $e->getMessage(), 'outcome_unknown' => true];
        }

        $batchNo = $createData['batch_no'] ?? null;
        if (!$batchNo) {
            // SPX accepted the request (ret_code 0) but gave nothing to follow it by.
            return ['success' => false, 'error' => 'SPX did not return a batch number for this booking.', 'outcome_unknown' => true];
        }

        $meta = [
            'service'          => $opts['service'] ?? 'standard',
            'parcel'           => $opts['parcel'] ?? null,
            'shipping_payment' => $opts['shipping_payment'] ?? 'sender_pay',
            'reference_id'     => $orderPayload['order_id'],
            'batch_no'         => $batchNo,
        ];

        $resultData = $this->pollCreateResult((int) $batchNo);

        if ($resultData !== null) {
            $outcome = $this->outcomeFromCreateResult($resultData);
            if ($outcome['ok']) {
                $meta['booked_response']         = $outcome['order'];
                $meta['estimated_shipping_fee']  = $outcome['order']['estimated_shipping_fee'] ?? null;

                return [
                    'success'         => true,
                    'tracking_number' => $outcome['order']['tracking_no'],
                    // Not in the create-result response — the next tracking
                    // poll (courier:poll-status, or a manual Refresh) fills
                    // this in. Null here is deliberate, not a bug.
                    'status'          => null,
                    'meta'            => $meta,
                ];
            }

            return ['success' => false, 'error' => "SPX booking failed: {$outcome['error']}"];
        }

        // Still pending after the bounded poll window.
        $meta['pending'] = true;
        return [
            'success'         => true,
            'tracking_number' => null,
            'status'          => 'creating',
            'meta'            => $meta,
        ];
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
     * (order not yet propagated — integration-brief.md §5's timing caveat:
     * "don't call Track immediately (<1-3s) after a successful Create").
     * api.md §1.2's own note says 13251 can surface EITHER way — the whole
     * call rejected with that ret_code, OR (ret_code:0 overall) this
     * tracking number landed in the per-item data.fail_list with that code
     * — so both surfaces are checked before deciding whether to back off and
     * retry (mirrors how cancelShipment() checks both surfaces for 13002).
     * Also fetches 2.2's actual fee on every call (returned as `fee_info`) —
     * see this method's dedicated comment below, and courier:poll-status's
     * docblock, for where that value is actually PERSISTED.
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

        // 2.2 Get Order Fee (batch_get_asf) — fetched here on every call
        // since this method is stateless (no access to the Order/
        // courier_meta to detect "already have it"). PollCourierStatus's
        // scheduled loop is the ONE place that actually persists the
        // returned `fee_info` into courier_meta.actual_fee (see that
        // command's docblock + this task's report) — the manual per-order
        // Refresh button also receives it back but doesn't persist it yet.
        // Best-effort — a fee lookup failure (e.g. not yet available
        // pre-pickup) never breaks tracking itself.
        $feeInfo = null;
        try {
            $feeData = $this->client->getActualFee([$trackingNumber]);
            foreach (($feeData['orders'] ?? []) as $fo) {
                if (($fo['tracking_no'] ?? null) === $trackingNumber) {
                    $feeInfo = $fo['fee_info'] ?? null;
                    break;
                }
            }
        } catch (\Throwable $e) {
            // best-effort only — see comment above.
        }

        return [
            'success'  => true,
            'status'   => $found['status_code'] ?? $found['status'] ?? null,
            'raw'      => $found,
            'links'    => !empty($found['tracking_link']) ? ['delivery' => $found['tracking_link']] : null,
            'timeline' => self::timelineFromRoutes($found['routes'] ?? null),
            'fee_info' => $feeInfo,
        ];
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
        return [...self::DELIVERED, '5002', '5003', '6003', ...self::CANCELLED];
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
            // Sanctioned per this task's brief: "the async-create pending
            // state maps to phase booking".
            return ['phase' => 'booking', 'step' => 1, 'failed' => false];
        }
        if ($s === 'creation_failed') {
            return ['phase' => 'failed', 'step' => 1, 'failed' => true];
        }
        if (in_array($s, self::DELIVERED, true)) {
            return ['phase' => 'delivered', 'step' => 3, 'failed' => false];
        }
        // Final-review fix (Finding 1): alongside SPX's own 7001, the
        // literal 'canceled'/'cancelled' the admin Cancel action stamps
        // (FulfillmentController::cancel() writes it for EVERY courier)
        // must bucket to the cancelled phase too — otherwise an
        // admin-cancelled SPX order read as 'to_handover' until the next
        // poll/webhook overwrote it with 7001.
        if (in_array($s, self::CANCELLED, true) || in_array($s, CourierOrder::CANCELED_STATUSES, true)) {
            return ['phase' => 'cancelled', 'step' => 1, 'failed' => true];
        }
        if (in_array($s, self::RETURNING_STATUSES, true)) {
            return ['phase' => 'returning', 'step' => 2, 'failed' => true];
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
            'amount'   => $amount,
            'currency' => 'PHP',
            'label'    => '₱' . number_format($amount, 2),
        ];
    }

    /**
     * SPX's serviceability answer, from the same 2.1 batch_check_order call
     * that quotes the fee, the only pre-booking question SPX will answer at
     * all (it otherwise refuses at create time, with the codes below).
     *
     * Implemented against docs/spx/api.md §2.1 and guide.md's error tables,
     * NOT against a live run: SPX's UAT host `test-stable.spx.ph` no longer
     * resolves in DNS, so every call from this machine dies in the resolver.
     * That is precisely why the third verdict exists: a dead DNS record is
     * caught below as `unknown` ("could not check", still clickable), never
     * as a refusal. If this ever silently reported "not serviceable" for an
     * SPX outage, the modal would be lying about coverage SPX never
     * evaluated.
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
            );
        }

        // api.md §2.1: a per-order failure comes back inside data.fail_list
        // with its own ret_code, rather than failing the whole envelope.
        $failed = ($data['fail_list'] ?? [])[0] ?? null;
        if (is_array($failed)) {
            return self::verdictForCode((int) ($failed['ret_code'] ?? 0), (string) ($failed['message'] ?? ''));
        }

        return ServiceabilityVerdict::unknown(ServiceabilityVerdict::KIND_THEIR_SIDE);
    }

    /** One SPX error code, classified by group, carrying SPX's words only when we do not recognise it. */
    private static function verdictForCode(int $code, string $message): ServiceabilityVerdict
    {
        [$kind, $isRefusal] = self::classify($code);
        $said = trim($message) !== '' ? trim($message) : null;

        return $isRefusal
            ? ServiceabilityVerdict::notServiceable($kind, $said)
            : ServiceabilityVerdict::unknown($kind, $said);
    }

    /**
     * 1.1 Get Pickup Timeslot (get_pickup_time), collapsed to ONE option per
     * DATE (owner call: date-only, no time-window picker in the booking
     * form). Each option's `value` is still the same small JSON blob
     * carrying exactly the three fields SPX's create/update endpoints need
     * (pickup_time, pickup_time_range_id, pickup_time_range) — createShipment()
     * decodes that same shape back out of $opts['pickup_slot'] unchanged
     * (see fulfillmentInfo()'s docblock) — it's just now populated with that
     * date's EARLIEST available window rather than a window the admin chose
     * directly: SPX's own range id 0 ("Now") when offered, else the first
     * entry in that date's slots[] array (SPX's own ordering, never
     * re-sorted). Which dates appear at all is entirely SPX's own decision
     * (its own cutoff, e.g. same-day pickup only offered before some cutoff
     * time) — this never synthesizes a date SPX didn't return. A day with no
     * usable slot is skipped, never shown as an empty option.
     */
    public function getPickupSlots(CourierOrder $order): array
    {
        if (!$this->client->hasAccountCredentials()) {
            return [];
        }

        try {
            $days = $this->client->getPickupTime(1);
        } catch (\Throwable $e) {
            return [];
        }

        if (!is_array($days)) {
            return [];
        }

        $options = [];
        foreach ($days as $day) {
            $pickupTime = $day['pickup_time'] ?? null;
            $slots = $day['slots'] ?? [];
            if ($pickupTime === null || empty($slots)) {
                continue;
            }

            $earliest = null;
            foreach ($slots as $slot) {
                if (!isset($slot['pickup_time_range_id'])) {
                    continue;
                }
                if ((int) $slot['pickup_time_range_id'] === 0) {
                    $earliest = $slot;
                    break;
                }
                if ($earliest === null) {
                    $earliest = $slot;
                }
            }
            if ($earliest === null) {
                continue;
            }

            $options[] = [
                'value' => json_encode([
                    'pickup_time'          => (int) $pickupTime,
                    'pickup_time_range_id' => (int) $earliest['pickup_time_range_id'],
                    'pickup_time_range'    => (string) ($earliest['pickup_time_range'] ?? ''),
                ]),
                'label' => \Carbon\Carbon::createFromTimestamp((int) $pickupTime)
                    ->timezone(config('app.timezone'))
                    ->format('D, M d'),
            ];
        }
        return $options;
    }

    /**
     * 1.6 Get AWB V2 (batch_get_shipping_label) — Task T3. SPX's own label
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
     *     why, this is a live-UAT gate finding, not documented behavior),
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

        // Final-review fix (Finding 1): api.md §1.6 — a cancelled order's
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

        // Live-UAT gate finding (fulfillment-workflow-v2 + SPX plan): UAT's
        // real batch_get_shipping_label response has NO `task_status` key at
        // all — it returns `{awb_link, fail_list}` immediately, not the
        // documented submit-then-poll {task_status: 1..5} shape (see
        // docs/spx/index.md's quirks list). So "done" is decided by
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

        // Live-UAT gate finding, round 3: awb_link can respond HTTP 200 with
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
     * Two-phase label fetch — see docs/spx/index.md's quirks list for the
     * full live-UAT gate history behind this shape (round 1 AND round 2 of
     * the finding).
     *
     * Phase 1 — 1.6 Get AWB V2 (the DOCUMENTED endpoint, SpxClient::
     * getShippingLabel()): a SHORT window only (2 attempts, one brief
     * backoff between them) rather than the original ~15s bounded poll.
     * Round 2's live-probing refined round 1's finding: this endpoint DOES
     * return the documented shape on UAT (task_status present) — it just
     * reliably sits at `task_status: 3` ("Running", `progress: "0.00%"`)
     * INDEFINITELY (probed 8+ minutes, never changes), so the original full
     * poll window only ever burned ~15s to arrive at the same "still stuck"
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
     * labelResultSettled()/fetchAndStoreLabel() parse logic as v2's — the
     * shape-handling fix from round 1 already covers it unchanged.
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
     * interface's `reference_id`. A payload missing `status_code` entirely
     * (malformed/unexpected) yields no events rather than a guessed one.
     */
    public function parseWebhook(array $payload): array
    {
        if (!isset($payload['status_code'])) {
            return [];
        }

        $timestamp = $payload['timestamp'] ?? null;

        return [[
            'reference_id'    => isset($payload['order_id']) ? (string) $payload['order_id'] : null,
            'tracking_number' => isset($payload['tracking_no']) ? (string) $payload['tracking_no'] : null,
            'status'          => (string) $payload['status_code'],
            'occurred_at'     => is_numeric($timestamp) ? date(DATE_ATOM, (int) $timestamp) : null,
            'remarks'         => isset($payload['message']) ? (string) $payload['message'] : null,
        ]];
    }

    /**
     * Task T3: verifies an inbound SPX webhook request's signature —
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
     * Preference order: courier_meta.tracking_links.delivery (written by
     * getTracking()'s 'links' -> the poll command / manual Refresh, mirroring
     * QuadX's own pattern), else booked_response.tracking_no_link (the link
     * 1.5 Get Order Create Result returns at booking time, before any
     * tracking poll has run yet).
     */
    public function trackingLinks(CourierOrder $order): array
    {
        $meta = $order->courier_meta ? (json_decode($order->courier_meta, true) ?: []) : [];
        if (!is_array($meta)) {
            return [];
        }

        $metaLinks = $meta['tracking_links'] ?? null;
        if (is_array($metaLinks) && !empty($metaLinks['delivery']) && is_string($metaLinks['delivery']) && str_starts_with($metaLinks['delivery'], 'http')) {
            return [['label' => 'Track', 'url' => $metaLinks['delivery']]];
        }

        $fallback = $meta['booked_response']['tracking_no_link'] ?? null;
        if (is_string($fallback) && str_starts_with($fallback, 'http')) {
            return [['label' => 'Track', 'url' => $fallback]];
        }

        return [];
    }

    /**
     * The one real capability this task ships: SPX's 3.2 Check Account
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
     * surface (unchanged fact from T1), but the vocabulary itself is not
     * actually unknown — SPX documents it as a stable table (STATUS_LABELS,
     * sourced from docs/spx/guide.md's "Order status code" section). So
     * unlike a true no-op, this now succeeds immediately (no HTTP call,
     * nothing to fail) and hands back that documented map — wiring it
     * through the EXACT SAME "Refresh from SPX" button -> {ok, labels} ->
     * `spx_status_labels` setting flow QuadX's live refresh already uses
     * (the account's status-label refresh), so no shared code
     * needed to change for this courier to participate in it. See
     * statusLabels() below for the second, complementary half of this
     * task's wiring: the same map made available WITHOUT requiring that
     * button click first.
     */
    public function refreshStatusLabels(): array
    {
        return ['ok' => true, 'labels' => self::STATUS_LABELS];
    }

    /**
     * Owner task (2026-08-08, "SPX status from code to their actual
     * meaning"): the documented vocabulary made available WITHOUT requiring
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
     * spx_pouch_presets has no first-class JSON/structured field type in
     * this interface yet, so it's declared as `type: 'text'` holding a raw
     * JSON string of `{label,length_cm,width_cm,height_cm,max_weight_kg}`
     * objects (see DEFAULT_POUCH_PRESETS above for the shape and defaults).
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
                'label' => 'Pouch presets (JSON)',
                'type'  => 'text',
                'help'  => 'JSON array of {label, length_cm, width_cm, height_cm, max_weight_kg}. '
                    . 'Defaults: Small 25×20×10cm/3kg, Medium 35×25×15cm/5kg, Large 45×35×20cm/10kg.',
            ],
        ];
    }

    // --- Task T2: pending-creation resolution ------------------------------

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

        return $tracking ? ['tracking_number' => (string) $tracking] : null;
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
        if (($refusal = self::supportRefusal($sender['flags'], $deliver['flags'], (bool) $order->cod, $fulfillment['collect_type'] === 1)) !== null) {
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
     * SPX's address levels are not PSGC's (docs/couriers/spx/index.md):
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
        $address  = (string) ($pickup->address_1 ?? '');
        $postCode = trim((string) ($pickup->postcode ?? ''));
        $name     = (string) ($pickup->contact_name ?? '') ?: 'Sender';
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
            'sender_name'           => $name,
            'sender_phone'          => $phone,
            'sender_detail_address' => $address,
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
        $address  = trim((string) $order->shipping_address_1);
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
            'deliver_name'           => trim($order->shipping_first_name . ' ' . $order->shipping_last_name) ?: 'Customer',
            'deliver_phone'          => $phone,
            'deliver_detail_address' => $address,
        ];
        // deliver_instruction is optional (api.md §1.4: N, "receiver's
        // remark, max length 256") — omit entirely rather than sending a
        // JSON null SPX never asked for. It carries the buyer's message to
        // the courier when one exists (core's courier_comment column).
        $instruction = trim((string) $order->courier_comment);
        if ($instruction !== '') {
            $info['deliver_instruction'] = mb_substr($instruction, 0, 256);
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
     * first, so the common raw shapes actually seen in the dev DB all
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

        // collect_type: 1 pickup, 2 drop-off. Pickup requires a scheduled
        // slot (pickup_time/pickup_time_range_id, from getPickupSlots());
        // the current booking form doesn't collect a slot choice yet, so
        // this defaults to drop-off — the only collect_type that never needs
        // one. The moment a future UI passes $opts['pickup_slot'] back (the
        // exact JSON string getPickupSlots() hands out as each option's
        // value), this switches to a real scheduled pickup automatically.
        $slot = $this->decodePickupSlot($opts['pickup_slot'] ?? null);
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

    private function decodePickupSlot(mixed $raw): ?array
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['pickup_time'], $decoded['pickup_time_range_id'], $decoded['pickup_time_range'])) {
            return null;
        }
        return [
            'pickup_time'          => (int) $decoded['pickup_time'],
            'pickup_time_range_id' => (int) $decoded['pickup_time_range_id'],
            'pickup_time_range'    => (string) $decoded['pickup_time_range'],
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
     * "Be careful: if all orders failed to be created, the task status is
     * still Done" (api.md §1.5) — success is NEVER inferred from task_status
     * alone, only from fail_list/fail_count, per this task's brief. An
     * explicit task_status 5 (Failed) is treated the same way — it's simply
     * another way this batch can be settled-but-unsuccessful, folded into
     * the same "no usable order" branch below via $failed.
     *
     * @return array{ok:bool, order?:array, error?:string}
     */
    private function outcomeFromCreateResult(array $resultData): array
    {
        $taskFailed = (int) ($resultData['task_status'] ?? 0) === 5;
        $failCount  = (int) ($resultData['fail_count'] ?? 0);
        $failList   = $resultData['fail_list'] ?? [];
        $orders     = $resultData['orders'] ?? [];

        if ($taskFailed || $failCount > 0 || !empty($failList) || empty($orders)) {
            $reason = $failList[0]['message']
                ?? ($taskFailed
                    ? ('SPX marked this batch as Failed' . (!empty($resultData['description']) ? ": {$resultData['description']}" : '.'))
                    : 'SPX could not create this shipment.');
            return ['ok' => false, 'error' => $reason];
        }

        $created = $orders[0];
        if (empty($created['tracking_no'])) {
            return ['ok' => false, 'error' => 'SPX reported success but returned no tracking number.'];
        }

        return ['ok' => true, 'order' => $created];
    }
}
