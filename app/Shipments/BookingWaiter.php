<?php

namespace App\Shipments;

use App\Models\Shipment;
use Illuminate\Support\Sleep;

/** `?wait=N` on POST /shipments: re-reads the row every 500 ms, for up to N (≤ 20) seconds, until booking settles. */
final class BookingWaiter
{
    public const MAX_SECONDS = 20;

    private const STEP_MS = 500;

    private const PENDING = ['queued', 'booking'];

    /** Clamped to 0..20; anything that is not a whole number is 0 (no waiting). */
    public static function seconds(mixed $raw): int
    {
        $n = filter_var($raw, FILTER_VALIDATE_INT);

        return $n === false ? 0 : max(0, min(self::MAX_SECONDS, $n));
    }

    public function wait(Shipment $s, int $seconds): Shipment
    {
        for ($i = 0, $steps = $seconds * (1000 / self::STEP_MS); $i < $steps && in_array($s->state, self::PENDING, true); $i++) {
            Sleep::for(self::STEP_MS)->milliseconds();
            $s->refresh();
        }

        return $s;
    }
}
