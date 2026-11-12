<?php

namespace App\Couriers\Support;

/**
 * Why a courier will not take a shipment, in words a seller can act on: a short title ("Too long for SPX") and a
 * detail ("Length is 35 cm. SPX takes up to 25 cm."). The courier's own text is never part of it; callers keep that
 * separately for support.
 */
final class CourierRefusal
{
    /** @param  string|null  $code  a stable machine code for API clients (`parcel_too_long`, `spx_no_delivery`, ...) */
    public function __construct(public readonly string $title, public readonly string $detail = '', public readonly ?string $code = null) {}

    public function withCode(?string $code): self
    {
        return new self($this->title, $this->detail, $code);
    }

    /** The one line stored as a shipment's error: "Too long for SPX. Length is 35 cm. SPX takes up to 25 cm." */
    public function message(): string
    {
        $title = rtrim(trim($this->title), '.');
        $detail = trim($this->detail);

        return $detail === '' ? "{$title}." : "{$title}. {$detail}";
    }

    /** @return array{title: string, detail: string, message: string} */
    public function toArray(): array
    {
        return ['title' => $this->title, 'detail' => $this->detail, 'message' => $this->message()];
    }

    /** A number as people write it: 35.000000 is 35, 12.50 is 12.5. Anything that is not a plain number is kept. */
    public static function number(mixed $value): string
    {
        $text = is_scalar($value) ? trim((string) $value) : '';
        if (preg_match('/^\d+(\.\d+)?$/', $text) !== 1) {
            return $text;
        }
        if (str_contains($text, '.')) {
            $text = rtrim(rtrim($text, '0'), '.');
        }
        $text = ltrim($text, '0');

        return $text === '' || str_starts_with($text, '.') ? '0'.$text : $text;
    }
}
