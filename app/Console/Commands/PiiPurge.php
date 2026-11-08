<?php

namespace App\Console\Commands;

use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Support\Settings;
use Illuminate\Console\Command;

/**
 * Daily: clears the personal data of finished shipments older than `privacy.retention_days` (default 180) and stamps
 * pii_purged_at. Finished: a terminal courier status (terminal_at, or a delivered/cancelled phase) or state failed
 * or cancelled; its age runs from COALESCE(terminal_at, cancelled_at, updated_at).
 *
 * Cleared: pickup, recipient, courier note, and each of its events' raw courier payload (set to {"purged":true}).
 * The rest (state, tracking, money, event statuses) stays.
 */
class PiiPurge extends Command
{
    protected $signature = 'ventaship:pii-purge';

    protected $description = 'Clear personal data of shipments finished longer ago than the retention window';

    public const DEFAULT_DAYS = 180;

    private const CHUNK = 1000;

    public function handle(Settings $settings): int
    {
        $days = $settings->get('privacy.retention_days', self::DEFAULT_DAYS);
        $days = is_numeric($days) && (int) $days >= 1 ? (int) $days : self::DEFAULT_DAYS;
        $cutoff = now()->subDays($days);

        $purged = 0;
        do {
            $ids = Shipment::query()
                ->whereNull('pii_purged_at')
                ->where(fn ($q) => $q->whereNotNull('terminal_at')
                    ->orWhereIn('state', ['failed', 'cancelled'])
                    ->orWhereIn('phase', ['delivered', 'cancelled']))
                ->whereRaw('COALESCE(terminal_at, cancelled_at, updated_at) < ?', [$cutoff->format('Y-m-d H:i:s')])
                ->orderBy('id')
                ->limit(self::CHUNK)
                ->pluck('id');

            if ($ids->isNotEmpty()) {
                $purged += Shipment::query()->whereIn('id', $ids->all())->toBase()->update([
                    'pickup' => null,
                    'recipient' => null,
                    'courier_note' => null,
                    'pii_purged_at' => now(),
                ]);
                ShipmentEvent::query()->whereIn('shipment_id', $ids->all())->toBase()->update(['raw' => '{"purged":true}']);
            }
        } while ($ids->count() === self::CHUNK);

        $this->info("Purged personal data of {$purged} shipments (retention {$days} days).");

        return self::SUCCESS;
    }
}
