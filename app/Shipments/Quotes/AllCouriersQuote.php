<?php

namespace App\Shipments\Quotes;

use App\Couriers\AccountSettings;
use App\Couriers\CourierRegistry;
use App\Models\CourierAccount;
use Throwable;

/**
 * POST /quotes with `couriers: "all"`: every active account asked at once, within a time budget, one entry per
 * account in a stable order (courier key, then account). A courier that has not answered by the deadline is
 * `timeout`; one that answered without a figure is `no_fee`; Ventaship never invents a fee.
 */
final class AllCouriersQuote
{
    public const DEFAULT_BUDGET_MS = 2500;

    public const MIN_BUDGET_MS = 1000;

    public const MAX_BUDGET_MS = 8000;

    /** Kept back from the budget to build and send the answer after the couriers. */
    public const RESPONSE_RESERVE_SECONDS = 0.15;

    /**
     * Couriers whose `service` is a real choice, with the default their adapter falls back to when the account sets
     * none (QuadXFulfillment::serviceFor). The others always use their own default.
     */
    private const SERVICE_COURIERS = ['quadx' => 'next_day'];

    public function __construct(private readonly QuoteRunner $runner) {}

    /**
     * @param  array<string, mixed>  $data  QuoteRequest::validated() without couriers/budget_ms
     * @param  float  $deadline  microtime(true) by which the answer must be ready
     * @return list<array<string, mixed>>
     */
    public function quote(array $data, float $deadline): array
    {
        unset($data['account_id'], $data['courier']);

        $accounts = CourierAccount::query()->where('status', 'active')->orderBy('courier')->orderBy('id')->get()
            ->filter(fn (CourierAccount $a) => CourierRegistry::has((string) $a->courier))
            ->values();

        $jobs = [];
        $services = [];
        foreach ($accounts as $account) {
            $services[$account->public_id] = $this->service($account, $data['service'] ?? null);
            $jobs[$account->public_id] = [
                'account_id' => $account->public_id,
                'data' => ['service' => $services[$account->public_id]] + $data,
            ];
        }

        $results = $jobs === [] ? [] : $this->runner->run($jobs, max(0.0, $deadline - microtime(true)));

        return $accounts->map(fn (CourierAccount $a) => $this->entry($a, $services[$a->public_id], $results[$a->public_id] ?? ['status' => 'timeout']))->all();
    }

    /** The service asked of this account: the requested one where the courier offers it, else the account's default. */
    private function service(CourierAccount $account, mixed $requested): ?string
    {
        $courier = (string) $account->courier;
        if (! array_key_exists($courier, self::SERVICE_COURIERS)) {
            return null;
        }

        try {
            $offered = array_map(fn (array $s) => (string) $s['value'], CourierRegistry::describe($courier)->getServiceTypes());
        } catch (Throwable) {
            $offered = [];
        }
        if (is_string($requested) && in_array($requested, $offered, true)) {
            return $requested;
        }

        $default = AccountSettings::for($account)->read($courier.'_default_service', self::SERVICE_COURIERS[$courier]);

        return is_string($default) && $default !== '' ? $default : self::SERVICE_COURIERS[$courier];
    }

    /**
     * @param  array<string, mixed>  $result  QuoteRunner's result for this account
     * @return array<string, mixed>
     */
    private function entry(CourierAccount $account, ?string $service, array $result): array
    {
        $entry = [
            'courier' => (string) $account->courier,
            'account_id' => $account->public_id,
            'service' => $service,
            'amount' => null,
            'currency' => null,
            'label' => null,
            'odz' => null,
            'remote_area_fee' => null,
            'serviceable' => null,
            'code' => null,
            'reason' => null,
        ];

        if (($result['status'] ?? null) === 'timeout') {
            return array_merge($entry, ['code' => 'timeout']);
        }

        $answer = ($result['status'] ?? null) === 'done' && is_array($result['answer'] ?? null) ? $result['answer'] : [];
        $fee = is_array($answer['fee'] ?? null) ? $answer['fee'] : null;
        $refusal = is_array($answer['refusal'] ?? null) ? $answer['refusal'] : null;

        if ($fee !== null) {
            return array_merge($entry, array_intersect_key($fee, array_flip(['amount', 'currency', 'label', 'odz', 'remote_area_fee'])), ['serviceable' => true]);
        }
        if ($refusal !== null) {
            return array_merge($entry, ['serviceable' => false, 'code' => (string) ($refusal['code'] ?? 'courier_refused'), 'reason' => $refusal['reason'] ?? null]);
        }

        return array_merge($entry, ['code' => 'no_fee']);
    }
}
