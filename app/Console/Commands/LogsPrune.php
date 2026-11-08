<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Daily: deletes logs past their keep window. With --signatures-only (hourly) only the replay-protection
 * signatures, which must outlive the 300 s request window, so they are kept 600 s.
 *
 *  - request_signatures   600 s
 *  - idempotency_keys     completed: 7 days. `processing` rows older than an hour are counted, never deleted.
 *  - webhook_receipts     unrouted: 7 days; all: 90 days
 *  - api_requests         30 days
 *  - courier_calls        90 days
 *  - outgoing_attempts    90 days
 */
class LogsPrune extends Command
{
    protected $signature = 'ventaship:logs-prune {--signatures-only : Prune only request signatures}';

    protected $description = 'Delete logs, receipts, idempotency keys and request signatures past their keep window';

    public const SIGNATURE_SECONDS = 600;

    private const CHUNK = 5000;

    public function handle(): int
    {
        $now = now();

        $deleted = ['request_signatures' => $this->prune('request_signatures', 'created_at', $now->copy()->subSeconds(self::SIGNATURE_SECONDS))];

        if (! $this->option('signatures-only')) {
            $deleted['idempotency_keys'] = $this->prune('idempotency_keys', 'created_at', $now->copy()->subDays(7), ['status' => 'completed']);
            $deleted['unrouted receipts'] = $this->prune('webhook_receipts', 'received_at', $now->copy()->subDays(7), ['status' => 'unrouted']);
            $deleted['webhook_receipts'] = $this->prune('webhook_receipts', 'received_at', $now->copy()->subDays(90));
            $deleted['api_requests'] = $this->prune('api_requests', 'created_at', $now->copy()->subDays(30));
            $deleted['courier_calls'] = $this->prune('courier_calls', 'created_at', $now->copy()->subDays(90));
            $deleted['outgoing_attempts'] = $this->prune('outgoing_attempts', 'created_at', $now->copy()->subDays(90));

            $stuck = DB::table('idempotency_keys')
                ->where('status', 'processing')
                ->where('created_at', '<', $now->copy()->subHour())
                ->count();
            if ($stuck > 0) {
                $this->warn("{$stuck} idempotency keys still processing after an hour (left in place).");
            }
        }

        foreach ($deleted as $what => $count) {
            $this->line("Deleted {$count} {$what}.");
        }

        return self::SUCCESS;
    }

    /** Deletes in chunks by id, so a large backlog never holds one long lock. @param array<string, string> $where */
    private function prune(string $table, string $column, \DateTimeInterface $before, array $where = []): int
    {
        $total = 0;
        do {
            $ids = DB::table($table)->where($where)->where($column, '<', $before)->orderBy('id')->limit(self::CHUNK)->pluck('id');
            if ($ids->isEmpty()) {
                break;
            }
            $total += DB::table($table)->whereIn('id', $ids->all())->delete();
        } while ($ids->count() === self::CHUNK);

        return $total;
    }
}
