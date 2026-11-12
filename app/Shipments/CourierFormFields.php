<?php

namespace App\Shipments;

use App\Couriers\AccountSettings;
use App\Couriers\CourierRegistry;
use App\Couriers\Lbc\LbcFulfillment;
use App\Couriers\Lbc\LbcPackaging;
use App\Couriers\Spx\SpxFulfillment;
use App\Models\CourierAccount;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * The courier-specific fields of the Add / Edit shipment form: which packaging each courier offers, which fields it
 * asks for and what they may hold. ShipmentFormRequest validates against the same lists the form renders, and
 * resources/js/shipmentForm.js (courierFields / courierReset) mirrors the show/hide rules.
 */
final class CourierFormFields
{
    /** The packaging that is the store's own box, so its size is typed. */
    public const OWN_PACKAGING = ['spx' => 'custom', 'lbc' => '16'];

    /** LBC's first tiles, in this order; every other PKG code sits behind More. */
    public const LBC_MAIN = ['45', '8', '165', '6', '164', '63', '7', '64', '16'];

    /** LBC Kilobox codes, whose size may be added. */
    public const LBC_KILOBOX = ['165', '6', '164', '63', '7', '64'];

    /** Who pays shipping, per courier, the first being the default. LBC always charges the account. */
    public const SHIPPING_PAYMENT = [
        'quadx' => ['cash' => 'Cash at pickup', 'net_off' => 'Deduct from remittance'],
        'spx' => ['sender_pay' => 'I pay', 'receiver_pay' => 'Receiver pays'],
    ];

    public const HANDOVER = ['dropoff' => 'Drop off myself', 'pickup' => 'Pick up'];

    public const DEFAULT_CATEGORY = 'General merchandise';

    public const NOTE_MAX = 256;

    public const LBC_DATES = 10;

    /** The account setting holding a courier's default parcel. */
    private const DEFAULT_PARCEL_SETTING = ['quadx' => 'quadx_default_parcel'];

    /** @return list<string> the 7 SPX item categories, verbatim */
    public static function categories(): array
    {
        return SpxFulfillment::PARCEL_ITEM_TYPES;
    }

    /**
     * The packaging tiles for an account, in the form's order. `more` marks the LBC codes behind the More fold-out.
     *
     * @return list<array{value: string, label: string, more: bool}>
     */
    public static function parcelOptions(CourierAccount $account): array
    {
        $courier = (string) $account->courier;
        try {
            $raw = CourierRegistry::for($account)->getParcelOptions();
        } catch (Throwable) {
            return [];
        }

        $sizes = self::presetSizes($account);
        $options = [];
        foreach ($raw as $o) {
            if (! is_array($o) || ! isset($o['value'], $o['label']) || ! empty($o['disabled'])) {
                continue;
            }
            $value = (string) $o['value'];
            $label = $courier === 'spx' && $value === self::OWN_PACKAGING['spx'] ? 'Own packaging' : (string) ($sizes[$value]['label'] ?? $o['label']);
            $more = $courier === 'lbc' && ! in_array($value, self::LBC_MAIN, true) && ctype_digit($value) && LbcPackaging::exists((int) $value);
            $options[$value] = ['value' => $value, 'label' => $label, 'more' => $more];
        }

        if ($courier === 'lbc') {
            $rank = array_flip(self::LBC_MAIN);
            uksort($options, fn ($a, $b) => [$options[$a]['more'], $rank[$a] ?? 99] <=> [$options[$b]['more'], $rank[$b] ?? 99]);
        }

        return array_values($options);
    }

    /**
     * The size each SPX preset fills in on the form, keyed by its parcel value. Other couriers have none.
     *
     * @return array<string, array{label: string, length_cm: float, width_cm: float, height_cm: float}>
     */
    public static function presetSizes(CourierAccount $account): array
    {
        if ((string) $account->courier !== 'spx') {
            return [];
        }
        try {
            $courier = CourierRegistry::for($account);
        } catch (Throwable) {
            return [];
        }

        return $courier instanceof SpxFulfillment ? $courier->presetSizes() : [];
    }

    /** @return list<string> */
    public static function parcelValues(CourierAccount $account): array
    {
        return array_column(self::parcelOptions($account), 'value');
    }

    /** The account setting's default parcel when it is set and still offered, else null (nothing chosen). */
    public static function defaultParcel(CourierAccount $account): ?string
    {
        $key = self::DEFAULT_PARCEL_SETTING[(string) $account->courier] ?? null;
        $value = $key === null ? null : AccountSettings::for($account)->read($key);

        return $value !== null && in_array($value, self::parcelValues($account), true) ? $value : null;
    }

    /** The default for who pays shipping, or null when the courier does not ask. */
    public static function defaultShippingPayment(string $courier): ?string
    {
        return array_key_first(self::SHIPPING_PAYMENT[$courier] ?? []);
    }

    /** Whether the parcel's size is required (SPX, own packaging), optional (LBC Kilobox) or not taken. */
    public static function sizeRule(string $courier, ?string $parcel): ?string
    {
        if ($courier === 'spx' || ($parcel !== null && (self::OWN_PACKAGING[$courier] ?? null) === $parcel)) {
            return 'required';
        }

        return $courier === 'lbc' && in_array($parcel, self::LBC_KILOBOX, true) ? 'optional' : null;
    }

    /**
     * The pickup time of an SPX slot as getPickupSlots() hands it out (JSON with pickup_time, pickup_time_range_id and
     * pickup_time_range), or null when it is not that shape or its time has passed.
     */
    public static function spxSlotTime(mixed $slot): ?int
    {
        $decoded = is_string($slot) ? json_decode($slot, true) : null;
        if (! is_array($decoded) || ! array_key_exists('pickup_time_range', $decoded) || ! is_string($decoded['pickup_time_range'])) {
            return null;
        }
        foreach (['pickup_time', 'pickup_time_range_id'] as $key) {
            if (! is_int($decoded[$key] ?? null)) {
                return null;
            }
        }

        return $decoded['pickup_time'] >= now()->getTimestamp() ? $decoded['pickup_time'] : null;
    }

    /**
     * Which courier fields show for a courier and its chosen packaging; the same as courierFields() in
     * resources/js/shipmentForm.js, used for the form's first render.
     *
     * @return array<string, bool>
     */
    public static function show(string $courier, ?string $parcel, bool $sizeOpen = false): array
    {
        $size = self::sizeRule($courier, $parcel);

        return [
            'service' => $courier === 'quadx',
            'weight' => in_array($courier, ['spx', 'lbc'], true),
            'size' => $size === 'required' || ($size === 'optional' && $sizeOpen),
            'sizeRequired' => $size === 'required',
            'addSize' => $size === 'optional',
            'category' => $courier === 'spx',
            'shippingPayment' => isset(self::SHIPPING_PAYMENT[$courier]),
            'handover' => $courier === 'spx',
            'pickupDate' => $courier === 'lbc',
            'note' => in_array($courier, ['quadx', 'spx'], true),
        ];
    }

    /**
     * The next LBC_DATES business days (Monday to Saturday, Manila) from LBC's first pickup date. No courier call.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function lbcDates(?CarbonImmutable $now = null): array
    {
        $date = LbcFulfillment::firstPickupDate($now);
        $dates = [];
        while (count($dates) < self::LBC_DATES) {
            if (! $date->isSunday()) {
                $dates[] = ['value' => $date->format('Y-m-d'), 'label' => LbcFulfillment::dateLabel($date)];
            }
            $date = $date->addDay();
        }

        return $dates;
    }
}
