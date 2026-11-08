<?php

namespace App\Fulfillment;

use Illuminate\Database\Eloquent\Builder;

/** The three ways the shipments list is cut: everything, still on its way, and done with. */
final class Views
{
    public const LABELS = ['all' => 'All', 'active' => 'Active', 'finished' => 'Finished'];

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

    public static function apply(Builder $q, mixed $view): Builder
    {
        return match (self::normalize($view)) {
            'active' => $q->whereRaw('NOT '.self::finishedSql()),
            'finished' => $q->whereRaw(self::finishedSql()),
            default => $q,
        };
    }
}
