<?php

namespace App\Couriers\Spx;

/**
 * SPX's maximum lengths for what a person types (portal/api/04 1.4 Create Order V2, "Request body"): sender_name and
 * deliver_name 64, sender_detail_address and deliver_detail_address 256, deliver_instruction 256. The other limited
 * fields (state, city, district, street, post code, order_id) come from SPX's own location list or from Ventaship,
 * never from the form.
 *
 * Checked at validation (the booking form and POST /shipments), so a long value is a field error, not an SPX
 * parameter error at booking. The detail address is address_1 and address_2 joined with ", ".
 */
final class SpxFieldLimits
{
    public const NAME_MAX = 64;

    public const DETAIL_ADDRESS_MAX = 256;

    public const INSTRUCTION_MAX = 256;

    /** "Jane Doe": first and last name as SPX receives them. */
    public static function fullName(mixed $first, mixed $last): string
    {
        return trim(self::text($first).' '.self::text($last));
    }

    /** "Unit 4, 123 Mango St": address lines 1 and 2, blank ones left out, joined with ", ". */
    public static function detailAddress(mixed $line1, mixed $line2): string
    {
        return implode(', ', array_filter([self::text($line1), self::text($line2)], fn (string $v) => $v !== ''));
    }

    /**
     * Which of the shipment's typed values are longer than SPX takes, by part.
     *
     * @param  array<string, mixed>|null  $recipient
     * @param  array<string, mixed>|null  $pickup
     * @return list<'recipient_name'|'recipient_address'|'pickup_name'|'pickup_address'|'note'>
     */
    public static function problems(?array $recipient, ?array $pickup, mixed $note): array
    {
        $problems = [];
        if (is_array($recipient)) {
            if (mb_strlen(self::fullName($recipient['first_name'] ?? null, $recipient['last_name'] ?? null)) > self::NAME_MAX) {
                $problems[] = 'recipient_name';
            }
            if (mb_strlen(self::detailAddress($recipient['address_1'] ?? null, $recipient['address_2'] ?? null)) > self::DETAIL_ADDRESS_MAX) {
                $problems[] = 'recipient_address';
            }
        }
        if (is_array($pickup)) {
            if (mb_strlen(self::text($pickup['contact_name'] ?? null)) > self::NAME_MAX) {
                $problems[] = 'pickup_name';
            }
            if (mb_strlen(self::detailAddress($pickup['address_1'] ?? null, $pickup['address_2'] ?? null)) > self::DETAIL_ADDRESS_MAX) {
                $problems[] = 'pickup_address';
            }
        }
        if (mb_strlen(self::text($note)) > self::INSTRUCTION_MAX) {
            $problems[] = 'note';
        }

        return $problems;
    }

    private static function text(mixed $value): string
    {
        return is_string($value) || is_int($value) ? trim((string) $value) : '';
    }
}
