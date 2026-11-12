<?php

namespace App\Couriers\Support;

use App\Support\Money;

/**
 * A courier's answer to "what would this shipment cost?", asked before anything is saved. Three answers:
 *
 *  - a fee: the courier priced it;
 *  - refused: the courier will not take it as it stands ($refusal says why, $courierText is its own words);
 *  - unavailable: no answer (not configured, unreachable, nothing priced). Never a refusal: booking stays possible.
 */
final class FeeQuote
{
    private function __construct(
        public readonly ?string $amount,
        public readonly string $currency,
        public readonly ?CourierRefusal $refusal,
        public readonly ?string $courierText,
    ) {}

    public static function fee(float|int|string $amount, string $currency = 'PHP'): self
    {
        return new self(Money::decimal($amount) ?? number_format((float) $amount, 2, '.', ''), $currency, null, null);
    }

    public static function refused(CourierRefusal $refusal, ?string $courierText = null): self
    {
        return new self(null, 'PHP', $refusal, self::clip($courierText));
    }

    public static function unavailable(?string $courierText = null): self
    {
        return new self(null, 'PHP', null, self::clip($courierText));
    }

    /** estimateFee()'s answer as a quote: its amount when it gave one, else unavailable. */
    public static function fromEstimate(?object $fee): self
    {
        return $fee !== null && isset($fee->amount) && is_numeric($fee->amount) && (float) $fee->amount >= 0
            ? self::fee($fee->amount, is_string($fee->currency ?? null) && $fee->currency !== '' ? $fee->currency : 'PHP')
            : self::unavailable();
    }

    public function isRefused(): bool
    {
        return $this->refusal !== null;
    }

    private static function clip(?string $text): ?string
    {
        $text = $text === null ? '' : trim((string) preg_replace('/\s+/u', ' ', $text));

        return $text === '' ? null : mb_substr($text, 0, 1000);
    }
}
