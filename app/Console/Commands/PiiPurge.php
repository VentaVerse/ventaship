<?php

namespace App\Console\Commands;

use App\Models\Shipment;
use App\Support\Settings;
use Illuminate\Console\Command;

/**
 * Daily: clears the personal data (pickup, recipient, courier note) of shipments that reached a terminal courier
 * status more than `privacy.retention_days` (default 180) ago, and stamps pii_purged_at. The rest of the shipment
 * (state, tracking, money, events) stays.
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
                ->whereNotNull('terminal_at')
                ->where('terminal_at', '<', $cutoff)
                ->whereNull('pii_purged_at')
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
            }
        } while ($ids->count() === self::CHUNK);

        $this->info("Purged personal data of {$purged} shipments (retention {$days} days).");

        return self::SUCCESS;
    }
}
