<?php

namespace App\Http\Resources;

use App\Couriers\CourierCapabilities;
use App\Couriers\CourierRegistry;
use App\Models\CourierAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A courier account as clients see it. An allowlist on purpose: no credential, webhook token, relay secret or
 * setting value can reach a client, whatever columns or settings the account gains later.
 *
 * @mixin CourierAccount
 */
class CourierAccountResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var CourierAccount $account */
        $account = $this->resource;
        $known = CourierRegistry::has((string) $account->courier);

        return [
            'id' => $account->public_id,
            'courier' => $account->courier,
            'label' => $account->label,
            'mode' => $account->mode,
            'status' => $account->status,
            'is_default' => (bool) $account->is_default,
            'issues' => $known ? $this->issues($account) : ['This courier is not installed.'],
            'capabilities' => $known ? CourierCapabilities::flags((string) $account->courier) : null,
        ];
    }

    /** @return list<string> the courier's own labels for the missing fields */
    private function issues(CourierAccount $account): array
    {
        try {
            return array_values(array_map('strval', CourierRegistry::for($account)->configurationIssues()));
        } catch (Throwable $e) {
            Log::warning('Courier account issues could not be read', ['account' => $account->public_id, 'error' => $e->getMessage()]);

            return ['The account settings could not be read.'];
        }
    }
}
