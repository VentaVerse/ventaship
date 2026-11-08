<?php

namespace App\Couriers\Lbc;

/**
 * What goes in one LBC booking: the packaging code, weight, size, value and
 * the amount to collect. Weights and volumes follow the Pickup Request data
 * dictionary: volumetric weight is L x W x H / 3500 (kg), the chargeable
 * weight is the higher of actual and volumetric, and CBM is for sea freight
 * only, so an air booking sends 0.
 *
 * Dimensions are required for BOX (the store's own packaging), because no
 * standard size stands behind it. For LBC's own packaging they may be left
 * out, all three together, and are then sent as 0: LBC publishes no
 * standard sizes for its packaging, and whether it accepts 0 there is still
 * to be confirmed with LBC. A refusal is a definite rejection, so it costs
 * no tracking number.
 */
final class LbcParcel
{
    /**
     * The lowest declared value of an insured parcel. An insured value below
     * it is raised to it (see LbcFulfillment::declaredValueFor()).
     */
    public const MIN_INSURED_DECLARED_VALUE = 500;

    public function __construct(
        public readonly int $pkg,
        public readonly float $weightKg,
        public readonly float $lengthCm,
        public readonly float $widthCm,
        public readonly float $heightCm,
        public readonly float $declaredValue,
        public readonly float $codAmount = 0.0,
        public readonly int $quantity = 1,
    ) {
        if (!is_finite($weightKg) || $weightKg <= 0) {
            throw new LbcException('The parcel weight must be above zero.');
        }

        $dims = ['length' => $lengthCm, 'width' => $widthCm, 'height' => $heightCm];
        foreach ($dims as $name => $value) {
            if (!is_finite($value) || $value < 0) {
                throw new LbcException("The parcel {$name} cannot be negative.");
            }
        }

        $given = count(array_filter($dims, fn (float $v) => $v > 0));
        if ($pkg === LbcPackaging::OWN_BOX && $given !== 3) {
            throw new LbcException('A box of your own needs its length, width and height.');
        }
        if ($given !== 0 && $given !== 3) {
            throw new LbcException('Give the length, width and height together, or leave all three empty.');
        }

        if ($quantity < 1) {
            throw new LbcException('The parcel quantity must be at least 1.');
        }

        // 0 is the uninsured declaration. LBC still has to confirm it accepts
        // 0; anything between 0 and the floor is a typing mistake.
        if (!is_finite($declaredValue) || $declaredValue < 0
            || ($declaredValue > 0 && $declaredValue < self::MIN_INSURED_DECLARED_VALUE)) {
            throw new LbcException('The declared value must be 0 (uninsured) or at least ' . self::MIN_INSURED_DECLARED_VALUE . '.');
        }

        if (!is_finite($codAmount) || $codAmount < 0) {
            throw new LbcException('The amount to collect cannot be negative.');
        }

        if (!LbcPackaging::exists($pkg)) {
            throw new LbcException("PKG {$pkg} is not in LBC's packaging list.");
        }
    }

    public function hasDimensions(): bool
    {
        return $this->lengthCm > 0 && $this->widthCm > 0 && $this->heightCm > 0;
    }

    public function volumetricWeight(): float
    {
        return round($this->lengthCm * $this->widthCm * $this->heightCm / 3500, 4);
    }

    public function chargeableWeight(): float
    {
        return round(max($this->weightKg, $this->volumetricWeight()), 2);
    }
}
