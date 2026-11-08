<?php

namespace App\Http\Requests\Api\V1;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** GET /shipments: list and reconcile (base plan §2). Every filter is optional; they combine with AND. */
class ListShipmentsRequest extends FormRequest
{
    public const MAX_IDS = 50;

    public const STATES = ['draft', 'queued', 'booking', 'booked', 'unknown', 'failed', 'cancelled'];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'ids' => ['sometimes', 'string', 'max:2000'],
            'client_reference' => ['sometimes', 'string', 'max:64'],
            'state' => ['sometimes', 'string', 'in:'.implode(',', self::STATES)],
            'needs_attention' => ['sometimes', 'string', 'in:true,false,1,0'],
            'updated_since' => ['sometimes', 'string', 'date'],
            'cursor' => ['sometimes', 'string', 'max:1000'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $v) {
            $ids = $this->ids();
            if ($ids === null) {
                return;
            }
            if (count($ids) > self::MAX_IDS) {
                $v->errors()->add('ids', 'At most '.self::MAX_IDS.' ids.');
            }
            foreach ($ids as $id) {
                if (! preg_match('/^shp_[0-9A-Za-z]{26}$/', $id)) {
                    $v->errors()->add('ids', 'Each id must be a shipment id (shp_…).');
                    break;
                }
            }
        }];
    }

    /** @return list<string>|null the requested public ids, or null when the filter is absent */
    public function ids(): ?array
    {
        $raw = $this->query('ids');
        if (! is_string($raw)) {
            return null;
        }

        return array_values(array_unique(array_filter(array_map('trim', explode(',', $raw)), fn ($id) => $id !== '')));
    }

    public function needsAttention(): ?bool
    {
        $raw = $this->validated('needs_attention');

        return $raw === null ? null : in_array($raw, ['true', '1'], true);
    }

    /** In the app zone, the zone timestamps are stored in. */
    public function updatedSince(): ?CarbonImmutable
    {
        $raw = $this->validated('updated_since');

        return $raw === null ? null : CarbonImmutable::parse($raw)->setTimezone(config('app.timezone'));
    }
}
