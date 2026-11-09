<?php

namespace App\Fulfillment;

use App\Models\ClientApp;
use App\Models\CourierAccount;
use App\Models\Shipment;
use App\Shipments\RecipientTokens;
use Illuminate\Database\Eloquent\Builder;

class ShipmentList
{
    /** @param array<string, mixed> $filters view, account, source, status, q */
    public static function query(array $filters): Builder
    {
        return self::scoped($filters, view: true, status: true)->with(['clientApp', 'courierAccount'])->orderByDesc('id');
    }

    /** Longest search kept; the rest is dropped. */
    public const QUERY_MAX = 100;

    /** Columns the search box matches anywhere in (case-insensitive under both drivers' default collations). */
    private const SEARCH_COLUMNS = ['client_reference', 'active_reference', 'courier_ref', 'tracking_number'];

    /**
     * Per view. Account, source, status and search apply; the view does not.
     *
     * @param array<string, mixed> $filters
     * @return array{all:int,needs:int,active:int,finished:int}
     */
    public static function counts(array $filters): array
    {
        $row = self::scoped($filters, view: false, status: true)->reorder()
            ->selectRaw('COUNT(*) as total'
                .', COALESCE(SUM(CASE WHEN '.Views::finishedSql().' THEN 1 ELSE 0 END), 0) as done'
                .', COALESCE(SUM(CASE WHEN '.Views::needsSql().' THEN 1 ELSE 0 END), 0) as needs')
            ->first();
        $all = (int) ($row->total ?? 0);
        $finished = (int) ($row->done ?? 0);

        return ['all' => $all, 'needs' => (int) ($row->needs ?? 0), 'active' => $all - $finished, 'finished' => $finished];
    }

    public static function sourceLabel(Shipment $s): string
    {
        if ($s->source === 'csv') {
            return 'CSV import';
        }

        return $s->clientApp?->name ?? 'Manual';
    }

    /** The search text as the list uses it: trimmed and at most QUERY_MAX characters; anything but a string is ''. */
    public static function normalizeQuery(mixed $q): string
    {
        return is_string($q) ? trim(mb_substr(trim($q), 0, self::QUERY_MAX)) : '';
    }

    /**
     * Reference, courier reference or tracking number containing the text, or a recipient whose name has every word
     * of it (through the blind index; whole words only). LIKE wildcards in the text match literally.
     */
    public static function search(Builder $q, mixed $text): Builder
    {
        $text = self::normalizeQuery($text);
        if ($text === '') {
            return $q;
        }

        $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $text).'%';
        $tokens = RecipientTokens::forWords($text);

        return $q->where(function (Builder $w) use ($like, $tokens) {
            foreach (self::SEARCH_COLUMNS as $col) {
                $w->orWhereRaw("shipments.{$col} LIKE ? ESCAPE '!'", [$like]);
            }
            if ($tokens !== []) {
                $w->orWhere(function (Builder $names) use ($tokens) {
                    foreach ($tokens as $t) {
                        $names->where('shipments.recipient_tokens', 'like', '%'.$t.'%');
                    }
                });
            }
        });
    }

    /** Account and source always; view and status when asked. Unknown values are ignored. */
    public static function scoped(array $filters, bool $view, bool $status): Builder
    {
        $q = Shipment::query();

        if ($view) {
            Views::apply($q, $filters['view'] ?? null);
        }
        if ($status) {
            StatusFilter::apply($q, $filters['status'] ?? null);
        }

        self::search($q, $filters['q'] ?? null);

        $account = $filters['account'] ?? null;
        if (is_string($account) && ($id = CourierAccount::query()->where('public_id', $account)->value('id')) !== null) {
            $q->where('courier_account_id', $id);
        }

        $source = $filters['source'] ?? null;
        if ($source === 'manual' || $source === 'csv') {
            $q->where('source', $source);
        } elseif (is_string($source) && str_starts_with($source, 'api:')
            && ($id = ClientApp::query()->where('public_id', substr($source, 4))->value('id')) !== null) {
            $q->where('source', 'api')->where('client_app_id', $id);
        }

        return $q;
    }
}
