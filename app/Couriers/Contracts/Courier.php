<?php

namespace App\Couriers\Contracts;

use App\Couriers\CourierOrder;
use App\Couriers\PickupPoint;
use App\Couriers\Support\ServiceabilityVerdict;

/**
 * Contract every courier module implements (QuadX, and future couriers).
 *
 * This is the FULFILLMENT side of shipping — booking a confirmed order with a
 * courier from the dedicated Fulfillment admin page. It is deliberately
 * separate from checkout: Venta's checkout never calls a courier. The admin
 * UI and CourierRegistry only ever talk to this interface, never to a concrete
 * courier — so adding a new courier never changes any shared code.
 */
interface Courier
{
    /** Machine key, stored on orders.courier_provider. e.g. 'quadx'. */
    public function getKey(): string;

    /** Human label shown in the booking UI. e.g. 'QuadX'. */
    public function getName(): string;

    /** Configured + turned on. */
    public function isEnabled(): bool;

    /**
     * Which of this courier's REQUIRED credential fields — the same fields
     * isEnabled() itself checks, e.g. SPX's App ID/App Secret pair, QuadX's
     * API Key/Secret Key pair — are currently empty. isEnabled() only
     * answers "configured or not" as one bool; this names WHICH fields are
     * missing, so the booking modal's step 1 (CourierRegistry::flagged() —
     * couriers whose ENABLED FLAG is on regardless of credential
     * completeness) can render a specific, actionable message ("missing App
     * ID / App Secret") instead of a generic one.
     *
     * Empty array = fully configured. Says nothing about whether the
     * enabled FLAG itself is on — that's flagged() vs isEnabled(), a
     * separate axis this method doesn't need to know about (a courier can
     * have zero configurationIssues() and still be flag-off, in which case
     * it's simply not in flagged() at all and this is never called for it
     * by the booking modal).
     *
     * @return string[] human-readable field labels (matching
     *   settingsSchema()'s own 'label' text for that field), in the order
     *   they should be filled in.
     */
    public function configurationIssues(): array;

    /**
     * Service levels for the booking form.
     * @return array<int, array{value:string,label:string}>
     */
    public function getServiceTypes(): array;

    /**
     * Parcel categories for the booking form.
     * @return array<int, array{value:string,label:string}>
     */
    public function getParcelOptions(): array;

    /**
     * Shipping-fee payment options (who pays the courier, how).
     * @return array<int, array{value:string,label:string}>
     */
    public function getShippingPaymentOptions(): array;

    /**
     * Book a shipment for an order.
     *
     * @param array $opts {
     *   service:string, parcel:string, weight_kg:float,
     *   length_cm:float, width_cm:float, height_cm:float,
     *   buyer_payment:string ('cod'|'prepaid'), shipping_payment:string
     * }
     * `outcome_unknown` (with success false): the request may have reached the courier (transport error,
     * timeout, a 5xx answer), so it is NOT a refusal; the caller marks the booking unknown and never retries it.
     * `notice` and `attention_reason` (with success true): stored on the shipment; an attention reason flags it.
     *
     * @return array{success:bool, tracking_number?:string, status?:string, meta?:array, error?:string,
     *               outcome_unknown?:bool, notice?:string|null, attention_reason?:string|null}
     */
    public function createShipment(CourierOrder $order, array $opts): array;

    /** @return array{success:bool, error?:string} */
    public function cancelShipment(string $trackingNumber): array;

    /**
     * Resolves an in-flight ASYNC shipment creation — a booking
     * whose createShipment() returned success with courier_status='creating'
     * because the courier's own create call is a submit-then-poll flow (SPX's
     * batch_create_order -> get_order_create_result) that didn't finish
     * within createShipment()'s own bounded poll window.
     *
     * Called by the `courier:poll-status` command's pending-creation pass for
     * every order sitting in courier_status='creating', in ADDITION to (and
     * before) that command's existing getTracking() pass. A synchronous
     * courier (QuadX) never produces courier_status='creating' in the first
     * place, so its implementation is a permanent no-op default: return null.
     *
     * Seller center: the courier writes nothing. The caller writes the
     * shipment from the answer.
     *
     * @return array{tracking_number?:string, courier_status?:string, meta?:array}|null
     *   null while still pending; ['tracking_number' => ...] once created;
     *   ['courier_status' => 'creation_failed', 'meta' => ...] when creation
     *   has permanently failed (the meta carries creation_error).
     */
    public function resolvePendingCreation(CourierOrder $order): ?array;

    /** @return array{success:bool, status?:string, raw?:array, error?:string} */
    public function getTracking(string $trackingNumber): array;

    /**
     * Statuses considered final — the scheduled poll stops checking an order
     * once it reaches one of these, so finished shipments aren't re-polled.
     * @return string[]
     */
    public function terminalStatuses(): array;

    /**
     * Courier statuses from which a shipment can still be canceled.
     * A null/unknown current status counts as cancelable (fresh bookings).
     */
    public function cancelableStatuses(): array;

    /**
     * Buckets a raw courier status onto Venta's common fulfillment spine,
     * shared by every courier (see App\Couriers\
     * Support\PhaseTone for the tone each bucket carries): `to_pack`,
     * `booking` (async create in flight), `to_handover` (booked, awaiting
     * courier collection/drop-off), `in_transit`, `delivered`, `failed`
     * (delivery failed / lost / damaged), `cancelled` (booking cancelled),
     * `returning` (coming back to the seller). (`unpaid` is a further tone
     * bucket a courier never returns, kept for the order-status side of the
     * queue.)
     *
     * This describes a SHIPMENT, and only a shipment: it drives row badges,
     * the drawer's stepper and timeline, and cancelable/terminal decisions.
     * It has NO say in which fulfillment tab an order appears in - that is
     * the core order status's placement alone
     * (FulfillmentService::tabBucket()).
     *
     * `to_pack` is deliberately NEVER returned here — "no booking exists
     * yet" isn't a courier status, it's the absence of one, so it's the
     * caller's job (see FulfillmentService::phaseFor()) to short-circuit to
     * `to_pack` before ever calling this method with a null tracking
     * number. A synchronous courier (like QuadX, whose createShipment()
     * always returns a status) never produces `booking` either — that
     * bucket exists for a courier whose booking creates asynchronously.
     *
     * Also returns the legacy 4-step happy-path stepper derivation for
     * backward compatibility with today's queue view (book -> handover ->
     * transit -> delivered): `failed`/`cancelled`/`returning` are terminal
     * BADGE states on that stepper, not steps of their own.
     *
     * @return array{
     *     phase: string,  // one of the 8 spine buckets above
     *     step: int,      // legacy stepper: 0 Pending,1 Booked,2 Shipped,3 Delivered
     *     failed: bool,   // legacy stepper: true = render step as a failure marker
     * }
     */
    public function statusPhase(?string $status): array;

    /**
     * Estimated shipping fee for booking this order with the given options
     * (same shape as createShipment()'s $opts) — shown to the operator on the
     * booking modal BEFORE they commit, where a courier can quote one (SPX's
     * batch_check_order), with a QuadX-safe default: couriers with
     * no quoting API return null and the UI simply renders no estimate slot —
     * "not supported" is silence, never a fake zero.
     *
     * @return object|null null = unsupported/unavailable; otherwise an object
     *   with `amount` (float), `currency` (string ISO code, e.g. 'PHP') and
     *   optionally `label` (string, pre-formatted display text).
     */
    public function estimateFee(CourierOrder $order, array $options): ?object;

    /**
     * Will this courier carry a parcel to this order's delivery address?
     * The booking modal says who will carry it before the operator chooses.
     *
     * Asked BEFORE anything is booked, from the booking modal's courier step,
     * so an address a courier will not serve is visible as a greyed card with
     * the courier's own words on it instead of a red banner after the
     * operator has already chosen.
     *
     * THREE answers, never two (see ServiceabilityVerdict): serviceable,
     * not_serviceable(reason), and unknown. The third is load-bearing: a
     * network timeout, a dead DNS record or an unparseable response is NOT a
     * refusal, and reporting it as one would tell an operator a courier
     * refuses an address it has never been asked about. `unknown` keeps the
     * card clickable: the courier itself stays the authority.
     *
     * The DEFAULT is `unknown`, which every courier gets for free by using
     * Support\AnswersUnknownServiceability (PHP interfaces carry no bodies,
     * so that trait is the default). A courier that cannot predict is never
     * forced to pretend.
     *
     * Implementations MUST bound their own network call to
     * ServiceabilityService::TIMEOUT_SECONDS and resolve anything slower to
     * `unknown`: the modal blocks on every courier at once, so one slow
     * courier must never hold the packing desk.
     *
     * This call MUST NOT create anything. It is a question, and the endpoints
     * behind it (QuadX /orders/estimates/rates, SPX batch_check_order) are
     * quote endpoints that book nothing.
     */
    public function checkServiceability(CourierOrder $order, PickupPoint $from): ServiceabilityVerdict;

    /**
     * Pickup time slots the operator can choose from when booking this order
     * (SPX's pickup-time endpoint), with a QuadX-safe default: couriers
     * without slot scheduling return [] and the booking form renders no slot
     * picker at all.
     *
     * @return array<int, array{value:string,label:string}>
     */
    public function getPickupSlots(CourierOrder $order): array;

    /**
     * @param string $format provider format hint (e.g. 'zebra'|'fuji')
     * @return array{success:bool, url?:string, manifest_url?:string, error?:string}
     *
     * `url` does NOT have to be the provider's own (possibly
     * perishable) link. A courier whose label link expires quickly and/or
     * has a limited open-count (SPX's AWB: valid 30 minutes, openable at
     * most 10 times — api.md §1.6) MAY instead download+store the asset
     * itself on first request and return the URL of THIS APP'S OWN
     * authenticated serving route, which streams the cached file on every
     * subsequent call without hitting the provider again. Same signature,
     * same `{success, url, error}` shape the caller (FulfillmentController::
     * label(), which just redirects to `url`) already expects — nothing
     * downstream needs to know which strategy a given courier chose.
     */
    public function printLabel(string $trackingNumber, string $format): array;

    /**
     * Normalize an incoming webhook payload into provider-agnostic events.
     * @return array<int, array{reference_id:?string, tracking_number:?string, status:string, occurred_at:?string, remarks:?string}>
     */
    public function parseWebhook(array $payload): array;

    /**
     * Declares every courier_account_settings key this courier reads/writes,
     * so ventaship:account-set's allowlist (and any settings screen) is
     * generated from a single source of truth.
     *
     * Each field is:
     *   key:          string   the literal setting key (conventionally
     *                          prefixed with getKey(), e.g. 'quadx_mode') —
     *                          renamed keys break stored data, so once shipped
     *                          a key is effectively permanent.
     *   label:        string   form label.
     *   type:         string   'text'|'password'|'secret'|'select'|'toggle'.
     *   options:      array    for type='select' only —
     *                          array<int,array{value:string,label:string}>,
     *                          same shape as getServiceTypes()/getParcelOptions().
     *   encrypted:    bool     stored via AccountSettings::writeSecret()
     *                          (CredentialVault). The settings
     *                          page renders it as a stored-secret control
     *                          (mask plus last four, View fetched on click,
     *                          Edit, Remove), whatever its type. A blank or
     *                          absent submission keeps the existing value;
     *                          only remove_secrets[] clears it. Default false.
     *   mode_scoped:  ?string  when set, this field only applies under that
     *                          mode value (e.g. 'sandbox'/'production') and
     *                          the settings page groups it under that mode's
     *                          reveal block. The field (of any courier's
     *                          schema) whose own key ends in "_mode" drives
     *                          which group is visible; its select options
     *                          are expected to cover every mode_scoped value
     *                          used elsewhere in the same schema. Default null
     *                          (always visible).
     *   help:         ?string  helper text under the field. Default null.
     *
     * @return array<int, array{key:string,label:string,type:string,options?:array,encrypted?:bool,mode_scoped?:?string,help?:?string}>
     */
    public function settingsSchema(): array;

    /**
     * Tracking URLs extracted from this order's courier_meta, in this
     * courier's own shape — courier_meta is entirely provider-owned (QuadX
     * nests its links under a "delivery"/"pickup" pair at refresh time, or
     * buried inside booked_response's own tracking_links at booking time; a
     * different courier might store one flat url, or none at all). Returned
     * in preference order (best/most useful link first) — the queue page
     * loops this rather than parsing courier_meta's shape itself.
     *
     * @return array<int, array{label:string, url:string}>
     */
    public function trackingLinks(\App\Couriers\CourierOrder $order): array;

    /**
     * Verify the currently saved credentials actually work by pinging the
     * provider. Backs the settings page's "Test Connection" button only —
     * never called on page load.
     *
     * @return array{ok:bool, message:string}
     */
    public function testConnection(): array;

    /**
     * Pull the live status vocabulary + display labels from the provider, to
     * cache as this courier's "{key}_status_labels" setting (used to
     * humanize raw courier statuses in the queue view and to label the
     * settings page's status-mapping table). Backs the settings page's
     * explicit "Refresh from {courier}" button only — never called on page
     * load or on a schedule.
     *
     * @return array{ok:bool, labels?:array<string,string>, error?:string}
     */
    public function refreshStatusLabels(): array;

}
