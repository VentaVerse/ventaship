<?php

namespace App\Fulfillment;

use App\Models\Shipment;
use Illuminate\Database\Eloquent\Builder;

/** The ways the shipments list is cut: everything, what needs the admin, still on its way, and done with. */
final class Views
{
    public const LABELS = ['all' => 'All', 'needs' => 'Needs action', 'active' => 'Active', 'finished' => 'Finished'];

    /** States that wait on the admin: never booked, refused, or booked with an unknown outcome. */
    public const NEEDS_STATES = ['failed', 'unknown', 'draft'];

    public static function normalize(mixed $view): string
    {
        return is_string($view) && isset(self::LABELS[$view]) ? $view : 'all';
    }

    /**
     * Portable SQL: delivered, cancelled or otherwise terminal, or purged (it can no longer be changed). Null-safe, so
     * NOT of it is the active set.
     */
    public static function finishedSql(string $table = 'shipments'): string
    {
        return "({$table}.terminal_at IS NOT NULL OR {$table}.pii_purged_at IS NOT NULL OR {$table}.state = 'cancelled' OR COALESCE({$table}.phase, '') IN ('delivered', 'cancelled'))";
    }

    /** Portable SQL for Needs action; the values are constants, never input. */
    public static function needsSql(string $table = 'shipments'): string
    {
        $states = "'".implode("', '", self::NEEDS_STATES)."'";

        return "(COALESCE({$table}.needs_attention, 0) = 1 OR {$table}.state IN ({$states}))";
    }

    /** The same rule as needsSql(), for one loaded row. */
    public static function needsAction(Shipment $s): bool
    {
        return (bool) $s->needs_attention || in_array($s->state, self::NEEDS_STATES, true);
    }

    public static function apply(Builder $q, mixed $view): Builder
    {
        return match (self::normalize($view)) {
            'needs' => $q->whereRaw(self::needsSql()),
            'active' => $q->whereRaw('NOT '.self::finishedSql()),
            'finished' => $q->whereRaw(self::finishedSql()),
            default => $q,
        };
    }
}
