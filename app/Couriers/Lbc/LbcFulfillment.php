<?php

namespace App\Couriers\Lbc;

use App\Couriers\AccountSettings;
use App\Couriers\Contracts\CancelsLocallyOnly;
use App\Couriers\Contracts\Courier;
use App\Couriers\Contracts\ProvidesPublicTrackingUrl;
use App\Couriers\Contracts\ThrottlesStatusPolling;
use App\Couriers\CourierOrder;
use App\Couriers\PickupOrigin;
use App\Couriers\PickupPoint;
use App\Couriers\ServiceabilityService;
use App\Couriers\Support\OrderWeight;
use App\Couriers\Support\ServiceabilityVerdict;
use App\Couriers\TrackingNumbers\TrackingNumberSeriesException;
use App\Couriers\TrackingNumbers\TrackingNumberSeriesService;
use App\Models\CourierAccount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * LBC Express (a Philippine courier).
 *
 * Four LBC APIs sit behind one gateway, each with its own subscription key:
 *
 *   | API                          | Transport | Auth header                  |
 *   |------------------------------|-----------|------------------------------|
 *   | Common API (address, cover)  | REST JSON | lbcOAkey                     |
 *   | Air Waybill                  | REST JSON | lbcOAkey                     |
 *   | Pickup Request V2            | XML/HTTP  | Ocp-Apim-Subscription-Key    |
 *   | Track and Trace V2           | SOAP      | lbcOAkey                     |
 *
 * Sending the wrong header answers `401 "Access denied due to missing
 * subscription key"`, which reads like a bad key rather than a wrong header
 * name, so the header is read from AUTH_HEADERS and nowhere else.
 *
 * EVERY REQUEST IS LIVE. LBC runs no test system: both keys of a
 * subscription reach the same live gateway, and a pickup request with
 * either books a real pickup. LBC also has no cancel endpoint. So a booking
 * is sent once and never retried, cancelling only resets the booking here
 * (see localCancelNotice()).
 *
 * WE ISSUE THE TRACKING NUMBER. LBC assigns each account a block of numbers
 * (tracking_series, added with ventaship:lbc-series-add) and does not check
 * uniqueness itself, so every booking takes the account's next number from
 * TrackingNumberSeriesService: a definite rejection releases it, and an
 * accepted or unknown answer spends it for good. An unknown answer is booked
 * as unconfirmed and handed back with an attention reason, for a person to
 * check with LBC; it is never retried.
 *
 * Coverage comes from LBC's own address search and serviceability check
 * (LbcAreaResolver), asked before booking. Tracking is polled (there is no
 * webhook), with intervals from pollIntervalMinutes().
 */
class LbcFulfillment implements Courier, ProvidesPublicTrackingUrl, CancelsLocallyOnly, ThrottlesStatusPolling
{
    /**
     * The gateway every documented LBC endpoint sits behind. Editable via
     * `lbc_gateway_url` because a published base URL is exactly the kind of
     * fact a vendor PDF outlives.
     */
    public const DEFAULT_GATEWAY_URL = 'https://lbcapigateway.lbcapps.com/';

    /** The auth header each API authenticates on. Read through authHeader(). */
    public const AUTH_HEADERS = [
        'common'     => 'lbcOAkey',
        'airwaybill' => 'lbcOAkey',
        'pickup'     => 'Ocp-Apim-Subscription-Key',
        'track'      => 'lbcOAkey',
    ];

    /**
     * The key sets, one per account environment. Both reach the live
     * gateway; LBC calls the sandbox one "test", but a booking sent with it
     * is still a real booking.
     */
    public const MODES = ['sandbox', 'production'];

    public const DEFAULT_MODE = 'sandbox';

    /**
     * What configurationIssues() says instead of naming all four keys when
     * none is stored: one fact rather than four labels on a small card.
     */
    public const NO_KEYS_AT_ALL = 'all four LBC keys';

    /** Named apart from the keys: no amount of credentials books without a block of numbers. */
    public const NO_TRACKING_SERIES = 'a tracking number series from LBC sales';

    /** The PKG code for the store's own box, the one parcel that needs measured dimensions. */
    public const OWN_PACKAGING_PKG = '16';

    /** The one way of paying LBC: freight is charged to the store's LBC account. */
    public const SHIPPING_PAYMENT_ACCOUNT = 'account';

    /**
     * Drop-off at an LBC branch is built (the gateway's value is DROPOFF) but
     * not offered: the option shows disabled and a booking asking for it is
     * refused until the store's contract with LBC is confirmed to allow it.
     */
    public const DROPOFF_ENABLED = false;

    /** LBC's public tracker, addressed by the tracking number alone. */
    public const PUBLIC_TRACKER_URL = 'https://www.lbcexpress.com/track/?tracking_no=';

    /** How far ahead a pickup date may be chosen. */
    public const PICKUP_DAYS_AHEAD = 30;

    /** The time of day sent with the chosen pickup date, Manila time. */
    public const PICKUP_TIME = '10:00:00';

    /** How many pickup dates the pickup-slots answer lists. */
    private const PICKUP_SLOT_COUNT = 10;

    private LbcClient $client;

    private TrackingNumberSeriesService $series;

    public function __construct(
        private readonly CourierAccount $account,
        private readonly AccountSettings $settings,
        ?LbcClient $client = null,
        ?TrackingNumberSeriesService $series = null,
    ) {
        $this->client = $client ?? new LbcClient($settings);
        $this->series = $series ?? app(TrackingNumberSeriesService::class);
    }

    public function getKey(): string
    {
        return 'lbc';
    }

    /**
     * "LBC", not "LBC Express", which is the name core's own
     * `shipping_couriers` row carries for the manual path. Two cards reading
     * "LBC Express" in one modal, one integrated and one not, would be a
     * puzzle.
     */
    public function getName(): string
    {
        return 'LBC';
    }

    /** The account is active, and every setting a booking needs is present. */
    public function isEnabled(): bool
    {
        return $this->account->status === 'active' && $this->configurationIssues() === [];
    }

    /**
     * Everything still standing between this account and an LBC booking: the
     * key set's missing subscription keys by the name of the API each
     * unlocks, the two account values the pickup request requires, and a
     * tracking number block with numbers left. Empty means LBC can book.
     */
    public function configurationIssues(): array
    {
        $missingKeys = [];
        foreach ($this->credentialFields() as $field) {
            if ($this->settings->read($field['key']) === null) {
                $missingKeys[] = $field['label'];
            }
        }

        if (count($missingKeys) === count($this->credentialFields()) && $missingKeys !== []) {
            $missingKeys = [self::NO_KEYS_AT_ALL];
        }

        $missing = $missingKeys;

        foreach ($this->merchantFields() as $field) {
            if (blank($this->settings->read($field['key']))) {
                $missing[] = $field['label'];
            }
        }

        // No block, or every number of every block used: nothing can be booked.
        $status = $this->trackingNumberSeriesStatus();
        if ($status === null || isset($status['error']) || $status['remaining'] <= 0) {
            $missing[] = self::NO_TRACKING_SERIES;
        }

        return $missing;
    }

    /** The active mode's encrypted fields: the four keys that mode sends. */
    private function credentialFields(): array
    {
        $mode = $this->mode();

        return array_values(array_filter(
            $this->settingsSchema(),
            fn (array $field) => !empty($field['encrypted'])
                && (($field['mode_scoped'] ?? null) === null || $field['mode_scoped'] === $mode)
        ));
    }

    /** The account values LBC assigns and the pickup request requires. */
    private function merchantFields(): array
    {
        return array_values(array_filter(
            $this->settingsSchema(),
            fn (array $field) => in_array($field['key'], ['lbc_client_code', 'lbc_shipper_account_no'], true)
        ));
    }

    /** Which key set this account sends: its environment, `sandbox` unless production. */
    public function mode(): string
    {
        return self::keySetOf($this->settings);
    }

    /** The key set for an account: its environment, `sandbox` unless production. */
    public static function keySetOf(AccountSettings $settings): string
    {
        $mode = (string) $settings->account()->mode;

        return in_array($mode, self::MODES, true) ? $mode : self::DEFAULT_MODE;
    }

    /** @throws \InvalidArgumentException for an API this class does not know */
    public static function authHeader(string $api): string
    {
        return self::AUTH_HEADERS[$api]
            ?? throw new \InvalidArgumentException('Unknown LBC API: ' . $api);
    }

    /** The settings key holding one API's key for the given key set (default: the account's). */
    public function keySetting(string $api, ?string $mode = null): string
    {
        return self::keySettingFor($api, $mode ?? $this->mode());
    }

    /** @throws \InvalidArgumentException for an API or key set this class does not know */
    public static function keySettingFor(string $api, string $mode): string
    {
        if (!array_key_exists($api, self::AUTH_HEADERS)) {
            throw new \InvalidArgumentException('Unknown LBC API: ' . $api);
        }

        if (!in_array($mode, self::MODES, true)) {
            throw new \InvalidArgumentException('Unknown LBC mode: ' . $mode);
        }

        return 'lbc_' . $mode . '_' . $api . '_key';
    }

    /** One API's key for the account's key set, decrypted, or null when unset or unreadable. */
    public function subscriptionKey(string $api): ?string
    {
        return $this->client->subscriptionKey($api);
    }

    /** The gateway, always with one trailing slash. */
    public function gatewayUrl(): string
    {
        return self::gatewayOf($this->settings);
    }

    /**
     * An account's gateway (lbc_gateway_url), always with one trailing slash.
     * Only an https URL with a host is used: the keys travel in its headers,
     * so anything else falls back to the published gateway.
     */
    public static function gatewayOf(AccountSettings $settings): string
    {
        $stored = trim((string) $settings->read('lbc_gateway_url'));
        $parts = $stored !== '' ? parse_url($stored) : false;
        $usable = is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && ($parts['host'] ?? '') !== ''
            && !isset($parts['user'])
            && !isset($parts['pass']);

        return rtrim($usable ? $stored : self::DEFAULT_GATEWAY_URL, '/') . '/';
    }

    /**
     * The per-account settings, in the order they are useful: where the
     * calls go, who LBC thinks we are, and one key per API per environment. On/off is the account's status, and the tracking number blocks are
     * tracking_series rows (ventaship:lbc-series-add), so neither is a
     * setting. There is no AppKey field: a pickup is accepted with that node
     * left out, authenticating on the header alone.
     */
    public function settingsSchema(): array
    {
        return [
            [
                'key'         => 'lbc_gateway_url',
                'section' => 'account',
                'label'       => 'API gateway',
                'type'        => 'text',
                'placeholder' => self::DEFAULT_GATEWAY_URL,
            ],
            [
                'key'   => 'lbc_client_code',
                'section' => 'account',
                'label' => 'Client code',
                'type'  => 'text',
                'hint'  => 'The code LBC assigned this store, up to five characters.',
            ],
            [
                'key'   => 'lbc_shipper_account_no',
                'section' => 'account',
                'label' => 'Shipper Account No',
                'type'  => 'text',
                'hint'  => 'Your LBC account number, from LBC sales.',
            ],
            [
                'key'         => 'lbc_sandbox_common_key',
                'label'       => 'Common API key',
                'type'        => 'secret',
                'encrypted'   => true,
                'mode_scoped' => 'sandbox',
            ],
            [
                'key'         => 'lbc_sandbox_airwaybill_key',
                'label'       => 'Air Waybill key',
                'type'        => 'secret',
                'encrypted'   => true,
                'mode_scoped' => 'sandbox',
            ],
            [
                'key'         => 'lbc_sandbox_pickup_key',
                'label'       => 'Pickup Request key',
                'type'        => 'secret',
                'encrypted'   => true,
                'mode_scoped' => 'sandbox',
            ],
            [
                'key'         => 'lbc_sandbox_track_key',
                'label'       => 'Track and Trace key',
                'type'        => 'secret',
                'encrypted'   => true,
                'mode_scoped' => 'sandbox',
            ],
            [
                'key'         => 'lbc_production_common_key',
                'label'       => 'Common API key',
                'type'        => 'secret',
                'encrypted'   => true,
                'mode_scoped' => 'production',
            ],
            [
                'key'         => 'lbc_production_airwaybill_key',
                'label'       => 'Air Waybill key',
                'type'        => 'secret',
                'encrypted'   => true,
                'mode_scoped' => 'production',
            ],
            [
                'key'         => 'lbc_production_pickup_key',
                'label'       => 'Pickup Request key',
                'type'        => 'secret',
                'encrypted'   => true,
                'mode_scoped' => 'production',
            ],
            [
                'key'         => 'lbc_production_track_key',
                'label'       => 'Track and Trace key',
                'type'        => 'secret',
                'encrypted'   => true,
                'mode_scoped' => 'production',
            ],
        ];
    }

    // --- Booking options ------------------------------------------------------

    /**
     * How the parcel reaches LBC. The values are the gateway's own (the
     * pickup request's MODE reference). Drop-off is listed so the choice is
     * visible, disabled until the store's contract allows it.
     */
    public function getServiceTypes(): array
    {
        return [
            ['value' => LbcPickupRequestBuilder::MODE_REGULAR, 'label' => 'Rider pickup'],
            [
                'value' => LbcPickupRequestBuilder::MODE_DROP_OFF,
                'label' => 'Drop off at an LBC branch',
                'disabled' => !self::DROPOFF_ENABLED,
            ],
        ];
    }

    /** LBC's packaging list, values being the PKG codes the request carries. */
    public function getParcelOptions(): array
    {
        return LbcPackaging::options();
    }

    public function getShippingPaymentOptions(): array
    {
        return [
            ['value' => self::SHIPPING_PAYMENT_ACCOUNT, 'label' => 'Charged to my LBC account'],
        ];
    }

    /**
     * What a booking chooses when the caller chose nothing. The parcel has no
     * default on purpose: a guessed packaging code books the wrong product.
     *
     * @return array{service: string, parcel: ?string, shipping_payment: string}
     */
    public function bookingDefaults(): array
    {
        return [
            'service' => LbcPickupRequestBuilder::MODE_REGULAR,
            'parcel' => null,
            'shipping_payment' => self::SHIPPING_PAYMENT_ACCOUNT,
        ];
    }

    /**
     * What the booking modal's LBC fields need beyond the option lists: the
     * pickup date range, the insured floor, which parcel needs dimensions,
     * and the confirm step that names the tracking number about to be spent.
     * Reads this store's own settings and series only; asks LBC nothing.
     *
     * @return array<string, mixed>
     */
    public function bookingFormContext(): array
    {
        $first = self::firstPickupDate();

        return [
            'pickupDateMin' => $first->format('Y-m-d'),
            'pickupDateMax' => $first->addDays(self::PICKUP_DAYS_AHEAD)->format('Y-m-d'),
            'pickupDateDefault' => $first->format('Y-m-d'),
            'insureFloor' => LbcParcel::MIN_INSURED_DECLARED_VALUE,
            'dimensionsRequiredFor' => [self::OWN_PACKAGING_PKG],
            'confirm' => true,
            'confirmNotice' => 'This sends a live pickup request to LBC. LBC has no cancel API, so once it is sent only a call to LBC can void it.',
            'nextTrackingNumber' => $this->nextTrackingNumber(),
        ];
    }

    /** A COD parcel is always insured, at the amount the rider collects. */
    public function insuresCodOrders(): bool
    {
        return true;
    }

    /**
     * The declared value sent with a booking.
     *
     * COD: always insured, at no less than the amount the rider collects.
     * Prepaid: insured when asked, otherwise 0. The insured value is the
     * shipment's declared_value (its total only when none was sent,
     * CourierOrder::insuredValue()).
     * An insured value is never below LbcParcel::MIN_INSURED_DECLARED_VALUE.
     * LBC still has to confirm that it accepts 0 for an uninsured parcel.
     */
    public static function declaredValueFor(CourierOrder $order, bool $insure, bool $cod): float
    {
        if (!$cod && !$insure) {
            return 0.0;
        }

        $value = sprintf('%.2F', $order->insuredValue());
        $floor = sprintf('%.2F', (float) LbcParcel::MIN_INSURED_DECLARED_VALUE);
        $collect = sprintf('%.2F', self::codAmountFor($order, $cod));

        foreach ([$floor, $collect] as $atLeast) {
            if (bccomp($atLeast, $value, 2) > 0) {
                $value = $atLeast;
            }
        }

        return (float) $value;
    }

    /** What the rider collects: the shipment's cod_amount on a COD shipment, nothing otherwise. */
    public static function codAmountFor(CourierOrder $order, bool $cod): float
    {
        return $cod ? (float) $order->collectAmount() : 0.0;
    }

    /**
     * Whether the buyer pays the rider: the shipment's own payment method.
     * plan() refuses a buyer_payment that contradicts it.
     */
    public static function isCod(CourierOrder $order, array $opts): bool
    {
        return (bool) $order->cod;
    }

    /** The first date a pickup may be asked for: the next business day (Monday to Saturday). */
    public static function firstPickupDate(?CarbonImmutable $now = null): CarbonImmutable
    {
        return LbcPickupService::businessDaysAhead(1, '00:00:00', $now)->startOfDay();
    }

    /**
     * The chosen pickup date (Y-m-d, Manila) as LBC's PreferredDate, or the
     * next business day when none was chosen.
     *
     * @throws LbcException naming what is wrong with the date
     */
    public static function preferredDateFor(?string $chosen, ?CarbonImmutable $now = null): CarbonImmutable
    {
        $first = self::firstPickupDate($now);
        [$h, $m, $s] = array_map('intval', explode(':', self::PICKUP_TIME));

        $chosen = trim((string) $chosen);
        if ($chosen === '') {
            return $first->setTime($h, $m, $s);
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $chosen, new \DateTimeZone(LbcPickupRequestBuilder::TIMEZONE));
        if ($date === false || $date->format('Y-m-d') !== $chosen) {
            throw new LbcException('The pickup date must be a date (YYYY-MM-DD).');
        }

        $date = CarbonImmutable::instance($date);
        if ($date->isSunday()) {
            throw new LbcException('LBC does not collect on Sundays. Choose another pickup date.');
        }
        if ($date->lessThan($first)) {
            throw new LbcException('The pickup date must be ' . $first->format('M j, Y') . ' or later.');
        }
        if ($date->greaterThan($first->addDays(self::PICKUP_DAYS_AHEAD))) {
            throw new LbcException('The pickup date can be at most ' . self::PICKUP_DAYS_AHEAD . ' days ahead.');
        }

        return $date->setTime($h, $m, $s);
    }

    /** The pickup date asked for, Y-m-d: `pickup_slot` when given, else `pickup_date`. Null when neither is. */
    private static function chosenPickupDate(array $opts): ?string
    {
        foreach (['pickup_slot', 'pickup_date'] as $key) {
            $value = trim((string) ($opts[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * The parcel, mode and date one booking would send, from the order and
     * the options chosen.
     *
     * @return array{parcel: LbcParcel, mode: string, preferred_at: CarbonImmutable, insured: bool, cod: bool, from: PickupPoint}
     * @throws LbcException naming the first thing to fix
     */
    public function plan(CourierOrder $order, array $opts): array
    {
        $mode = strtoupper(trim((string) ($opts['service'] ?? ''))) ?: LbcPickupRequestBuilder::MODE_REGULAR;
        if (!in_array($mode, LbcPickupRequestBuilder::MODES, true)) {
            throw new LbcException('Choose how the parcel reaches LBC: Rider pickup.');
        }
        if ($mode === LbcPickupRequestBuilder::MODE_DROP_OFF && !self::DROPOFF_ENABLED) {
            throw new LbcException('Drop off at an LBC branch is not offered yet. Choose Rider pickup.');
        }

        $pkg = trim((string) ($opts['parcel'] ?? ''));
        if (!ctype_digit($pkg) || !LbcPackaging::exists((int) $pkg)) {
            throw new LbcException('Choose an LBC parcel.');
        }

        $payment = (string) ($opts['shipping_payment'] ?? self::SHIPPING_PAYMENT_ACCOUNT);
        if ($payment !== self::SHIPPING_PAYMENT_ACCOUNT) {
            throw new LbcException('LBC freight is charged to your LBC account.');
        }

        $weight = isset($opts['weight_kg']) && is_numeric($opts['weight_kg'])
            ? (float) $opts['weight_kg']
            : OrderWeight::kg($order);
        if ($weight === null || $weight <= 0) {
            throw new LbcException('Enter the parcel weight in kg.');
        }

        $from = PickupOrigin::from($opts);
        if ($from === null) {
            throw new LbcException('The shipment has no pickup address.');
        }

        if (($problem = $order->paymentProblem($opts)) !== null) {
            throw new LbcException($problem);
        }

        $cod = self::isCod($order, $opts);
        // COD is insured whatever the caller sent: the declared value is the
        // cover for the money the rider collects.
        $insured = $cod || filter_var($opts['insure'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $parcel = new LbcParcel(
            pkg: (int) $pkg,
            weightKg: round($weight, 2),
            lengthCm: (float) ($opts['length_cm'] ?? 0),
            widthCm: (float) ($opts['width_cm'] ?? 0),
            heightCm: (float) ($opts['height_cm'] ?? 0),
            declaredValue: self::declaredValueFor($order, $insured, $cod),
            codAmount: self::codAmountFor($order, $cod),
        );

        return [
            'parcel' => $parcel,
            'mode' => $mode,
            // The pickup date: `pickup_slot` (Y-m-d) when given, else the shipment's `pickup_date` (Y-m-d).
            'preferred_at' => self::preferredDateFor(self::chosenPickupDate($opts)),
            'insured' => $insured,
            'cod' => $cod,
            'from' => $from,
        ];
    }

    // --- Booking ----------------------------------------------------------------

    /**
     * Book one LBC pickup. Address resolution and coverage are asked first;
     * anything that would stop the booking refuses before a number is taken.
     * The request is sent once. LBC's answer decides the number: saved
     * (spent, booked), rejected (released, nothing booked) or unknown (spent,
     * booked as unconfirmed with an `attention_reason`, so the shipment
     * cannot be booked a second time before somebody checks with LBC).
     */
    public function createShipment(CourierOrder $order, array $opts): array
    {
        if (!$this->isEnabled()) {
            return ['success' => false, 'error' => 'LBC is not configured. Fill in its settings first.'];
        }

        try {
            $plan = $this->plan($order, $opts);
        } catch (LbcException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }

        $service = $this->pickupService();

        try {
            $draft = $service->prepare($order, $plan['from'], $plan['parcel'], $plan['preferred_at'], $plan['mode']);
        } catch (LbcException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }

        if ($draft->blockers !== []) {
            return ['success' => false, 'error' => 'Not sent to LBC. ' . implode(' ', $draft->blockers)];
        }

        try {
            $sent = $service->send($draft);
        } catch (LbcException|TrackingNumberSeriesException $e) {
            return ['success' => false, 'error' => 'Not sent to LBC. ' . $e->getMessage()];
        }

        /** @var LbcPickupResult $result */
        $result = $sent['result'];
        $number = (string) $sent['issued']->number;

        if ($result->outcome === LbcPickupResult::REJECTED) {
            return [
                'success' => false,
                'error' => 'LBC refused the booking. ' . $result->summary()
                    . " Tracking number {$number} was not used and is the next one issued.",
            ];
        }

        $confirmed = $result->outcome === LbcPickupResult::ACCEPTED;
        $status = $confirmed ? LbcTrackingStatus::BOOKED : LbcTrackingStatus::UNCONFIRMED;

        $notices = [];
        $attention = null;
        if (!$confirmed) {
            $notices[] = "LBC did not confirm pickup request {$number} ({$result->summary()}). Call LBC to check it before booking this shipment again.";
            // LBC may hold the pickup: the shipment needs a person, and is
            // never retried automatically.
            $attention = mb_substr("LBC did not confirm pickup request {$number}: {$result->summary()} The number is kept for this shipment. Check with LBC whether the pickup exists.", 0, 255);
        }
        if ($sent['issued']->nearEnd) {
            $notices[] = "Only {$sent['issued']->remaining} LBC tracking numbers are left. Request a new block from LBC.";
        }

        // Everything the waybill needs, as sent, so a label printed later
        // always matches the booking. The account number is not kept: the
        // waybill does not carry it.
        $request = $sent['fields'];
        unset($request['ShipperAccountNo']);

        return [
            'success' => true,
            'tracking_number' => $number,
            'status' => $status,
            'notice' => $notices === [] ? null : implode(' ', $notices),
            'attention_reason' => $attention,
            'meta' => [
                'mode' => $this->mode(),
                'service' => $plan['mode'],
                'parcel' => (string) $plan['parcel']->pkg,
                'shipping_payment' => self::SHIPPING_PAYMENT_ACCOUNT,
                'pickup_date' => $plan['preferred_at']->format('Y-m-d'),
                'insured' => $plan['insured'],
                'cod' => $plan['cod'],
                'declared_value' => $plan['parcel']->declaredValue,
                'cod_amount' => $plan['parcel']->codAmount,
                'weight_kg' => $plan['parcel']->weightKg,
                'length_cm' => $plan['parcel']->lengthCm,
                'width_cm' => $plan['parcel']->widthCm,
                'height_cm' => $plan['parcel']->heightCm,
                'rate_estimate' => $draft->destination->rate,
                'delivery_area' => [
                    'psgc' => $draft->destination->psgcCode,
                    'location' => $draft->destination->fullLocationName,
                    'area_code' => $draft->destination->areaCode,
                ],
                'pickup_request' => $request,
                'lbc_answer' => [
                    'outcome' => $result->outcome,
                    'code' => $result->code(),
                    'message' => mb_substr($result->summary(), 0, 500),
                    'http_status' => $result->httpStatus,
                ],
            ],
        ];
    }

    /**
     * LBC has no cancel endpoint, so this only lets the shared cancel reset
     * the booking here. The tracking number stays spent: LBC may still hold
     * the pickup, and reusing the number would give two parcels one
     * identity. See localCancelNotice().
     */
    public function cancelShipment(string $trackingNumber): array
    {
        // Once LBC has the parcel a reset here would put a moving parcel
        // back in To Pack, so only a booking still waiting for its rider can
        // be cancelled. QuadX and SPX refuse that case themselves; LBC
        // cannot be asked, so it is refused here.
        $status = CourierOrder::forTracking($this->getKey(), $trackingNumber, $this->account)?->courier_status;
        if ($status !== null && $status !== '' && !in_array($status, $this->cancelableStatuses(), true)) {
            $said = LbcTrackingStatus::LABELS[$status] ?? $status;

            return ['success' => false, 'error' => "LBC already has this parcel ({$said}), so it cannot be cancelled here. Call LBC to recall it."];
        }

        Log::info('LBC booking cancelled locally; LBC itself is not told', ['tracking_no' => $trackingNumber]);

        return ['success' => true];
    }

    public function localCancelNotice(): string
    {
        return 'LBC has no cancel API. Call LBC to void this booking.';
    }

    /** LBC books synchronously, so no shipment is ever left in courier_status='creating'. */
    public function resolvePendingCreation(CourierOrder $order): ?array
    {
        return null;
    }

    /**
     * Track and Trace for one number. "No Record Found" is not a failure: it
     * is what LBC answers until a rider first scans the parcel, so it reports
     * success with no new status and leaves the booking as it is.
     */
    public function getTracking(string $trackingNumber): array
    {
        try {
            $answer = $this->client->track($trackingNumber);
        } catch (LbcException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }

        $code = (string) ($answer['status_code'] ?? '');

        if ($code === '201') {
            return ['success' => true, 'status' => null, 'timeline' => [], 'raw' => ['status_code' => $code, 'status_message' => $answer['status_message']]];
        }

        if ($code !== '0001') {
            $said = trim($code . ' ' . (string) ($answer['status_message'] ?? ''));

            return ['success' => false, 'error' => $said !== '' ? "LBC answered {$said}." : 'LBC did not answer.'];
        }

        $read = LbcTrackingStatus::fromHistory($answer['history']);

        return [
            'success' => true,
            'status' => $read['status'],
            'timeline' => $read['timeline'],
            'raw' => ['status_code' => $code, 'status_message' => $answer['status_message']],
        ];
    }

    /**
     * Delivered, remitted, returned or cancelled: the poll stops asking. A
     * failed attempt is not final, because LBC re-delivers.
     */
    public function terminalStatuses(): array
    {
        return [LbcTrackingStatus::DELIVERED, LbcTrackingStatus::COD_REMITTED, LbcTrackingStatus::RETURNED, LbcTrackingStatus::CANCELED, 'cancelled'];
    }

    /** Before a rider has the parcel. The cancel is local only either way. */
    public function cancelableStatuses(): array
    {
        return [LbcTrackingStatus::BOOKED, LbcTrackingStatus::UNCONFIRMED];
    }

    public function statusPhase(?string $status): array
    {
        return LbcTrackingStatus::phase($status);
    }

    /**
     * Before pickup nothing changes until a rider scans the parcel, so an
     * hour between questions is enough; once it moves, half an hour.
     */
    public function pollIntervalMinutes(?string $status): int
    {
        return in_array((string) $status, ['', LbcTrackingStatus::BOOKED, LbcTrackingStatus::UNCONFIRMED], true) ? 60 : 30;
    }

    /**
     * LBC's rate for the delivery barangay, from the serviceability check.
     * An estimate for the admin only: LBC bills against the account, and
     * checkout never asks a courier for a rate.
     */
    public function estimateFee(CourierOrder $order, array $options): ?object
    {
        if (!$this->isEnabled()) {
            return null;
        }

        try {
            $area = $this->resolver(ServiceabilityService::TIMEOUT_SECONDS * 2)
                ->resolve($order->shipping_barangay, $order->shipping_city, $order->shipping_state, $order->shipping_state_code);
        } catch (LbcException) {
            return null;
        }

        if ($area->serviceable !== true || $area->rate === null || $area->rate <= 0) {
            return null;
        }

        return (object) [
            'amount' => $area->rate,
            'currency' => 'PHP',
            'label' => '₱' . number_format($area->rate, 2) . ' (LBC estimate)',
            'shipping' => $area->rate,
            'insurance' => null,
            'total' => $area->rate,
            'estimate' => true,
            'category' => $area->category,
            // LBC's own ODZ signal is its serviceability answer (LbcPickupRequestBuilder sends ODZ=false for a
            // serviceable barangay), and a quote is only given for a serviceable one.
            'odz' => false,
        ];
    }

    /**
     * Whether LBC serves the delivery barangay, from its own search and
     * serviceability check, asked by PSGC code. An address LBC's search
     * cannot find, or a check that did not answer, is not a refusal.
     */
    public function checkServiceability(CourierOrder $order, PickupPoint $from): ServiceabilityVerdict
    {
        if (!$this->isEnabled()) {
            return ServiceabilityVerdict::unknown(ServiceabilityVerdict::KIND_UNREACHABLE);
        }

        try {
            $area = $this->resolver(ServiceabilityService::TIMEOUT_SECONDS)
                ->resolve($order->shipping_barangay, $order->shipping_city, $order->shipping_state, $order->shipping_state_code);
        } catch (LbcException) {
            return ServiceabilityVerdict::unknown(ServiceabilityVerdict::KIND_UNREACHABLE);
        }

        // ODZ is LBC's serviceability answer itself: the booking sends ODZ=false for a serviceable barangay, true otherwise.
        if ($area->serviceable === true) {
            return ServiceabilityVerdict::serviceable()->withOdz(false);
        }

        if ($area->serviceable === false) {
            return ServiceabilityVerdict::notServiceable(ServiceabilityVerdict::KIND_DESTINATION, $area->status)->withOdz(true);
        }

        $problem = $area->problems[0] ?? null;
        if ($problem === null || str_contains($problem, 'could not answer')) {
            return ServiceabilityVerdict::unknown(ServiceabilityVerdict::KIND_UNREACHABLE);
        }

        return ServiceabilityVerdict::unknown(null, $problem);
    }

    /** "Tue, Nov 10": how a pickup date (a slot's `value`) reads in lists and on a shipment. */
    public static function dateLabel(\Carbon\CarbonInterface $date): string
    {
        return $date->format('D, M j');
    }

    /**
     * The dates a pickup can be asked for, Monday to Saturday from the next
     * business day. The booking form uses a date picker; this list serves
     * callers that choose from a list (the ERP). The value is what
     * `pickup_slot` carries for LBC: a date, Y-m-d.
     */
    public function getPickupSlots(CourierOrder $order): array
    {
        $date = self::firstPickupDate();
        $slots = [];

        while (count($slots) < self::PICKUP_SLOT_COUNT) {
            if (!$date->isSunday()) {
                $slots[] = ['value' => $date->format('Y-m-d'), 'label' => self::dateLabel($date)];
            }
            $date = $date->addDay();
        }

        return $slots;
    }

    /**
     * The Air Waybill, rendered by LBC from the request this booking sent
     * (kept in courier_meta), then stored so every later print serves the
     * same file without asking LBC again. LBC renders whatever it is sent,
     * so the label is never rebuilt from today's shipment or settings.
     */
    public function printLabel(string $trackingNumber, string $format): array
    {
        // The number becomes part of a storage path: only digits are ever looked up or stored.
        if (preg_match('/^\d{1,' . TrackingNumberSeriesService::MAX_DIGITS . '}$/', $trackingNumber) !== 1) {
            return ['success' => false, 'error' => 'That is not an LBC tracking number.'];
        }

        $order = CourierOrder::forTracking($this->getKey(), $trackingNumber, $this->account);
        if (!$order) {
            return ['success' => false, 'error' => 'No LBC booking found for this tracking number.'];
        }

        if (($this->statusPhase($order->courier_status)['phase'] ?? null) === 'cancelled') {
            return ['success' => false, 'error' => 'This LBC booking is cancelled, so its waybill is void.'];
        }

        $path = self::labelStoragePath($trackingNumber);
        $disk = Storage::disk('local');

        if (!$disk->exists($path)) {
            $meta = $order->courier_meta ? (json_decode($order->courier_meta, true) ?: []) : [];
            $request = $meta['pickup_request'] ?? null;
            if (!is_array($request) || ($request['TrackingNo'] ?? null) !== $trackingNumber) {
                return ['success' => false, 'error' => 'This booking has no stored LBC request, so its waybill cannot be made.'];
            }

            try {
                $label = (new LbcWaybillBuilder())->label($request, (string) ($request['Shipper'] ?? ''), (string) $order->courier_comment);
                $pdf = $this->client->generateWaybill($label);
            } catch (LbcException $e) {
                return ['success' => false, 'error' => 'The LBC waybill could not be made. ' . $e->getMessage()];
            } catch (\Throwable $e) {
                Log::warning('LBC waybill failed', ['shipment' => $order->id, 'error' => $e->getMessage()]);

                return ['success' => false, 'error' => 'The LBC waybill could not be made. Try again in a moment.'];
            }

            if ($pdf === null) {
                return ['success' => false, 'error' => 'LBC returned no waybill PDF. Try again in a moment.'];
            }

            $disk->put($path, $pdf);
        }

        // The stored PDF on the local (private) disk; the label store serves it.
        return [
            'success' => true,
            'path' => $path,
        ];
    }

    /** Where a rendered waybill is kept on the local (private) disk. */
    public static function labelStoragePath(string $trackingNumber): string
    {
        return 'labels/lbc/' . $trackingNumber . '.pdf';
    }

    /** LBC pushes nothing: tracking is polled. */
    public function parseWebhook(array $payload): array
    {
        return [];
    }

    public function publicTrackingUrl(string $trackingNumber, array $courierMeta): ?string
    {
        $trackingNumber = trim($trackingNumber);

        return $trackingNumber === '' ? null : self::PUBLIC_TRACKER_URL . rawurlencode($trackingNumber);
    }

    public function trackingLinks(CourierOrder $order): array
    {
        $url = $this->publicTrackingUrl((string) $order->courier_tracking_number, []);

        return $url ? [['label' => 'Track', 'url' => $url]] : [];
    }

    /** A read-only city search on the Common API key. Books nothing. */
    public function testConnection(): array
    {
        if ($this->subscriptionKey('common') === null) {
            return ['ok' => false, 'message' => 'The Common API key is not set.'];
        }

        try {
            $rows = $this->client->withTimeout(10)->searchCities('makati', null, 1);
        } catch (LbcException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        return $rows !== null
            ? ['ok' => true, 'message' => 'Connected. LBC answered an address search.']
            : ['ok' => false, 'message' => 'LBC did not answer an address search. Check the Common API key.'];
    }

    /** LBC publishes no status list; its words come from the Track and Trace guide. */
    public function publishesStatusList(): bool
    {
        return false;
    }

    public function refreshStatusLabels(): array
    {
        return ['ok' => false, 'error' => 'LBC publishes no status list. Its statuses are listed below already.'];
    }

    /** @return array<string, string> */
    public function statusLabels(): array
    {
        return LbcTrackingStatus::LABELS;
    }

    /**
     * How much of the account's tracking number blocks is left. Null for an
     * unsaved (schema-only) account or one with no block yet.
     *
     * @return array{blocks:int,size:int,remaining:int,next:?int,near_end:bool}|array{error:string}|null
     */
    public function trackingNumberSeriesStatus(): ?array
    {
        if (!$this->account->exists) {
            return null;
        }

        try {
            return $this->series->status($this->account);
        } catch (TrackingNumberSeriesException $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /** The tracking number the next booking would take, or null when none can be issued. */
    public function nextTrackingNumber(): ?string
    {
        $status = $this->trackingNumberSeriesStatus();

        return isset($status['next']) && $status['remaining'] > 0 ? (string) $status['next'] : null;
    }

    private function resolver(int $timeout): LbcAreaResolver
    {
        return new LbcAreaResolver($this->client->withTimeout($timeout));
    }

    /** The pickup flow for this account, on its own client and blocks. */
    private function pickupService(): LbcPickupService
    {
        return new LbcPickupService($this->settings, $this->client, new LbcAreaResolver($this->client), $this->series);
    }
}
