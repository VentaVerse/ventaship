<?php

namespace App\Shipments;

use App\Couriers\CourierRegistry;
use App\Models\AuditEntry;
use App\Models\ShipmentStatus;
use App\Models\StatusMapping;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The owner's shipment statuses and each courier's mapping onto them. A disabled or deleted status leaves the courier
 * statuses that pointed at it unmapped (PhaseResolver); enabling it again maps them back. A mapping only changes the
 * shipment status shown and sent: cancelling and finishing a parcel stay with the courier's own codes.
 */
final class ShipmentStatuses
{
    /** Couriers whose status list is known and working, so they get a Status mapping tab. */
    public const MAPPABLE = ['spx', 'quadx'];

    public function __construct(private readonly PhaseResolver $phases) {}

    /** @return Collection<int, ShipmentStatus> */
    public function all(): Collection
    {
        return ShipmentStatus::query()->orderBy('sort')->orderBy('id')->get();
    }

    /** @return array<string, string> key => name, enabled only, for the mapping dropdowns */
    public function options(): array
    {
        return ShipmentStatus::query()->where('enabled', true)->orderBy('sort')->orderBy('id')->pluck('name', 'key')->all();
    }

    /** The words for a status key a shipment carries: its name, or the key spelled out once deleted. */
    public static function nameOf(string $key): string
    {
        if ($key === PhaseResolver::UNMAPPED) {
            return 'Unmapped';
        }

        $name = ShipmentStatus::query()->where('key', $key)->value('name');

        return is_string($name) && $name !== '' ? $name : Str::ucfirst(str_replace('_', ' ', $key));
    }

    /** @param  array{type?: string, id?: mixed, ip?: ?string}  $actor */
    public function create(string $name, bool $enabled, array $actor): ShipmentStatus
    {
        return DB::transaction(function () use ($name, $enabled, $actor) {
            $status = ShipmentStatus::query()->create([
                'key' => $this->newKey($name),
                'name' => $name,
                'enabled' => $enabled,
                'sort' => ((int) ShipmentStatus::query()->max('sort')) + 10,
            ]);
            $this->audit('shipment_status.created', $status->key, ['name' => $name, 'enabled' => $enabled], $actor);

            return $status;
        });
    }

    /** @param  array{type?: string, id?: mixed, ip?: ?string}  $actor */
    public function update(ShipmentStatus $status, string $name, bool $enabled, array $actor): void
    {
        DB::transaction(function () use ($status, $name, $enabled, $actor) {
            $changes = array_filter(['name' => $name !== $status->name ? $name : null, 'enabled' => $enabled !== $status->enabled ? $enabled : null], fn ($v) => $v !== null);
            if ($changes === []) {
                return;
            }
            $status->forceFill(['name' => $name, 'enabled' => $enabled])->save();
            $this->audit('shipment_status.updated', $status->key, $changes, $actor);
        });
    }

    /** @param  array{type?: string, id?: mixed, ip?: ?string}  $actor */
    public function setEnabled(ShipmentStatus $status, bool $enabled, array $actor): void
    {
        $this->update($status, $status->name, $enabled, $actor);
    }

    /**
     * Deleted for good: mappings that chose it become unmapped, so a later status can never inherit them. Shipments
     * already carrying it keep the key; they show its words until their next courier status.
     *
     * @param  array{type?: string, id?: mixed, ip?: ?string}  $actor
     */
    public function delete(ShipmentStatus $status, array $actor): void
    {
        DB::transaction(function () use ($status, $actor) {
            StatusMapping::query()->where('phase', $status->key)->update(['phase' => PhaseResolver::UNMAPPED, 'updated_at' => now()]);
            $status->delete();
            $this->audit('shipment_status.deleted', $status->key, ['name' => $status->name], $actor);
        });
    }

    /**
     * A courier's statuses as the mapping tab lists them, in the courier's own order.
     *
     * @return list<array{code: string, label: string, current: ?string}>
     */
    public function mapping(string $courier): array
    {
        $rows = [];
        foreach ($this->courierStatuses($courier) as $code => $label) {
            $rows[] = ['code' => (string) $code, 'label' => $label, 'current' => $this->phases->phase($courier, (string) $code)];
        }

        return $rows;
    }

    /**
     * Saves the chosen status per courier status (a key, or '' for unmapped). A choice equal to what the courier's own
     * default gives is stored as no row, so the default keeps applying (and comes back if its status is re-enabled).
     *
     * @param  array<string, string>  $chosen
     * @param  array{type?: string, id?: mixed, ip?: ?string}  $actor
     * @return int how many courier statuses changed
     */
    public function saveMapping(string $courier, array $chosen, array $actor): int
    {
        $options = $this->options();
        $known = $this->courierStatuses($courier);

        return DB::transaction(function () use ($courier, $chosen, $actor, $options, $known) {
            $changed = [];
            foreach ($known as $code => $label) {
                $code = (string) $code;
                if (! array_key_exists($code, $chosen)) {
                    continue;
                }
                $pick = (string) $chosen[$code];
                if ($pick !== '' && ! array_key_exists($pick, $options)) {
                    continue; // a status disabled meanwhile: leave this row as it is
                }
                if ($pick === (string) $this->phases->phase($courier, $code)) {
                    continue;
                }

                $default = $this->phases->courierPhase($courier, $code);
                $defaultShown = $this->phases->usable($default) ? $default : '';
                $row = StatusMapping::query()->firstOrNew(['courier' => $courier, 'courier_status' => $code]);

                if ($pick === $defaultShown && ($row->label === null || $row->label === '')) {
                    $row->exists && $row->delete();
                } else {
                    $row->phase = $pick === '' ? PhaseResolver::UNMAPPED : $pick;
                    $row->save();
                }
                $changed[$code] = $pick === '' ? null : $pick;
            }

            if ($changed !== []) {
                $this->audit('status_mapping.updated', $courier, ['statuses' => $changed], $actor);
            }

            return count($changed);
        });
    }

    /** @return array<string, string> code => the courier's own words */
    private function courierStatuses(string $courier): array
    {
        $courierClass = CourierRegistry::describe($courier);

        return method_exists($courierClass, 'statusLabels') ? (array) $courierClass->statusLabels() : [];
    }

    /** A deleted built-in added again by its name takes its key back, so the couriers' own defaults find it again. */
    private function newKey(string $name): string
    {
        $base = Str::limit(Str::slug($name, '_'), 28, '') ?: 'status';
        $key = $base;
        for ($i = 2; ShipmentStatus::query()->where('key', $key)->exists() || in_array($key, ['draft', 'booking', PhaseResolver::UNMAPPED], true); $i++) {
            $key = $base.'_'.$i;
        }

        return $key;
    }

    /** @param  array{type?: string, id?: mixed, ip?: ?string}  $actor */
    private function audit(string $action, string $subject, array $changes, array $actor): void
    {
        AuditEntry::query()->create([
            'actor_type' => $actor['type'] ?? 'admin',
            'actor_id' => isset($actor['id']) ? (string) $actor['id'] : null,
            'ip' => $actor['ip'] ?? null,
            'action' => $action,
            'subject_type' => str_starts_with($action, 'status_mapping') ? 'courier' : 'shipment_status',
            'subject_id' => $subject,
            'changes' => $changes,
            'created_at' => now(),
        ]);
    }
}
