<?php

namespace App\Couriers\Support;

/**
 * One courier's answer to "will you carry this parcel to this address?".
 *
 * THREE answers, never two. The third is the whole point of this object: a
 * courier we could not reach has NOT refused the address, and reporting it as
 * a refusal would make the booking modal lie during an outage. `unknown` is
 * therefore a first-class verdict that the UI renders as a still-clickable
 * card, because the courier itself remains the authority and the operator is
 * allowed to go and ask it.
 *
 * `kind` is WHICH failure this is, once we have recognised it: a destination
 * we do not reach, an origin we will not collect from, COD we will not do
 * there, a postcode we do not accept, a parcel we will not carry. A
 * recognised kind is what lets the modal write ONE sentence of our own that
 * names the destination and reads the same whichever courier said it.
 *
 * `reason` is the courier's OWN words, verbatim, never our paraphrase. It is
 * shown ONLY when `kind` is null, because an unrecognised failure is a
 * failure whose only useful description is theirs (owner ruling, 2026-08-12:
 * printing both our sentence and theirs was redundant). It is deliberately
 * left NULL for a transport failure, where the message would be curl's or
 * ours and never the courier's.
 *
 * `code` is the machine-readable WHY (`missing_postcode`, `spx_unlisted_barangay`, ...) when our own check knows it,
 * for API clients; null when the courier itself answered.
 *
 * `fees` is the price map the same call happened to return (QuadX's
 * /orders/estimates/rates answers with a fee per parcel category), keyed by
 * that courier's own parcel value so the booking form can print an exact fee
 * beside each parcel option. Empty is normal: a courier can serve an address
 * without quoting anything.
 */
final class ServiceabilityVerdict implements \JsonSerializable
{
    public const SERVICEABLE = 'serviceable';
    public const NOT_SERVICEABLE = 'not_serviceable';
    public const UNKNOWN = 'unknown';

    /**
     * The failures we recognise. Each one has a sentence of our own in the
     * booking modal, so the courier's raw text is never printed beside it.
     *
     * The first four are refusals of THIS shipment (the card is inert). The
     * last three are not: a parcel we guessed, a pickup slot our own form
     * chooses, and the courier's own machinery falling over say nothing about
     * whether the address can be served, so they resolve to `unknown` and the
     * card stays clickable.
     */
    public const KIND_DESTINATION = 'destination';
    public const KIND_ORIGIN = 'origin';
    public const KIND_COD = 'cod';
    public const KIND_POSTCODE = 'postcode';
    public const KIND_PARCEL = 'parcel';
    public const KIND_SLOT = 'slot';
    public const KIND_THEIR_SIDE = 'their_side';
    public const KIND_UNREACHABLE = 'unreachable';

    /** @param array<string, float> $fees parcel value => amount */
    private function __construct(
        public readonly string $status,
        public readonly ?string $reason = null,
        public readonly array $fees = [],
        public readonly string $currency = 'PHP',
        public readonly ?string $kind = null,
        public readonly ?string $code = null,
    ) {
    }

    /** @param array<string, float|int|string> $fees */
    public static function serviceable(array $fees = [], string $currency = 'PHP'): self
    {
        $clean = [];
        foreach ($fees as $key => $amount) {
            if (is_numeric($amount)) {
                $clean[(string) $key] = (float) $amount;
            }
        }

        return new self(self::SERVICEABLE, null, $clean, $currency);
    }

    /**
     * The courier said no. $kind is which no, when we recognise it; $reason
     * must be what IT said, and is what gets printed when we do not.
     */
    public static function notServiceable(?string $kind = null, ?string $reason = null, ?string $code = null): self
    {
        return new self(self::NOT_SERVICEABLE, $reason, [], 'PHP', $kind, $code);
    }

    /** We could not ask, or could not understand the answer. Never a refusal. */
    public static function unknown(?string $kind = null, ?string $reason = null, ?string $code = null): self
    {
        return new self(self::UNKNOWN, $reason, [], 'PHP', $kind, $code);
    }

    public function isServiceable(): bool
    {
        return $this->status === self::SERVICEABLE;
    }

    public function isRefusal(): bool
    {
        return $this->status === self::NOT_SERVICEABLE;
    }

    /** The cheapest quoted parcel, which is what a card prints as a from-price. Null when nothing was quoted. */
    public function cheapest(): ?float
    {
        return $this->fees === [] ? null : min($this->fees);
    }

    /** @return array{status:string, kind:?string, code:?string, reason:?string, fees:array<string,float>, currency:string, from:?float} */
    public function toArray(): array
    {
        return [
            'status'   => $this->status,
            'kind'     => $this->kind,
            'code'     => $this->code,
            'reason'   => $this->reason,
            'fees'     => $this->fees,
            'currency' => $this->currency,
            'from'     => $this->cheapest(),
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /** Rebuild from toArray(): the session cache stores the array, never the object. */
    public static function fromArray(array $data): self
    {
        return match ($data['status'] ?? self::UNKNOWN) {
            self::SERVICEABLE     => self::serviceable($data['fees'] ?? [], $data['currency'] ?? 'PHP'),
            self::NOT_SERVICEABLE => self::notServiceable($data['kind'] ?? null, $data['reason'] ?? null, $data['code'] ?? null),
            default               => self::unknown($data['kind'] ?? null, $data['reason'] ?? null, $data['code'] ?? null),
        };
    }
}
