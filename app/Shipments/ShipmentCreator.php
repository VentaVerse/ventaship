<?php

namespace App\Shipments;

use App\Addresses\Customers;
use App\Api\ApiError;
use App\Couriers\AccountResolver;
use App\Couriers\Support\PhPhone;
use App\Models\ClientApp;
use App\Models\Shipment;
use App\Shipments\Jobs\BookShipment;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use App\Support\Settings;
use Illuminate\Contracts\Database\ConcurrencyErrorDetector;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

/**
 * Stores a new shipment from validated client input. No courier is called here: a queued shipment is booked by
 * BookShipment once this transaction commits, and a draft waits for POST /shipments/{id}/submit.
 *
 * A client reference is held by at most one live shipment per client app (`active_reference`, unique per app).
 * A failed or cancelled shipment gives its reference up, so the client can try again under the same reference.
 */
final class ShipmentCreator
{
    /** States in which a shipment no longer holds its client reference. */
    public const RELEASED_STATES = ['failed', 'cancelled'];

    private const ITEMS_SUMMARY_MAX = 255;

    private const REF_PAD = 6;

    private const CONCURRENCY_ATTEMPTS = 3;

    public function __construct(private readonly Settings $settings) {}

    /**
     * `now: true` books in this request; its callers must not hold a transaction.
     *
     * @param  array<string, mixed>  $data  CreateShipmentRequest::validated()
     *
     * @throws ApiError 422 account_not_found / account_misconfigured, 409 active_shipment_exists
     * @throws LogicException `now: true` inside an open transaction
     */
    public function create(?ClientApp $app, array $data, string $source = 'api', bool $now = false): Shipment
    {
        if ($now) {
            self::assertNoTransaction();
        }

        $account = AccountResolver::resolve($data['account_id'] ?? null, $data['courier'] ?? null);
        AccountResolver::assertBookable($account);

        $state = filter_var($data['submit'] ?? true, FILTER_VALIDATE_BOOLEAN) ? 'queued' : 'draft';
        $attributes = self::attributes($data) + [
            'client_app_id' => $app?->getKey(),
            'courier_account_id' => $account->getKey(),
            'courier' => $account->courier,
            'source' => $source,
            'state' => $state,
            'client_reference' => $data['client_reference'],
            'active_reference' => $data['client_reference'],
            'order_label' => $data['order_label'] ?? null,
        ];

        $shipment = $this->storeHoldingReference($app, $attributes);
        $this->rememberCustomer($shipment, true);

        if ($state === 'queued' && $now) {
            return $this->bookNow($shipment->id);
        }

        if ($state === 'queued') {
            BookShipment::dispatch($shipment->id)->afterCommit();
        }

        return $shipment->setRelation('courierAccount', $account);
    }

    /**
     * Rewrites an unbooked manual shipment (draft or failed) from validated input. A failed shipment goes back to
     * draft with its error and attention cleared, so submit() can book it again. The client reference stays unless
     * the input carries another one; either way the shipment holds it again, and a live holder is a 409.
     *
     * @param  array<string, mixed>  $data  same shape as create()
     *
     * @throws ApiError 409 not_editable / active_shipment_exists, 422 account_not_found / account_misconfigured
     */
    public function update(Shipment $shipment, array $data): Shipment
    {
        $account = AccountResolver::resolve($data['account_id'] ?? null, $data['courier'] ?? null);
        // Keeping its own account is always allowed (submit() refuses to book on one that cannot); a new one must be bookable.
        if ((string) $account->getKey() !== (string) $shipment->courier_account_id) {
            AccountResolver::assertBookable($account);
        }

        $reference = ($data['client_reference'] ?? null) ?: $shipment->client_reference;

        try {
            $updated = $this->withManualReferenceLock(null, $reference, fn () => DB::transaction(function () use ($shipment, $data, $account) {
                $s = Shipment::query()->whereKey($shipment->getKey())->lockForUpdate()->firstOrFail();
                if (! $s->isEditable()) {
                    throw new ApiError(409, 'not_editable', 'Only unbooked manual shipments can be changed.');
                }

                $reference = ($data['client_reference'] ?? null) ?: $s->client_reference;
                $holder = Shipment::query()
                    ->where('client_app_id', $s->client_app_id)
                    ->where('active_reference', $reference)
                    ->whereKeyNot($s->getKey())
                    ->lockForUpdate()
                    ->first();
                if ($holder !== null) {
                    if (! in_array($holder->state, self::RELEASED_STATES, true)) {
                        throw $this->duplicate($holder);
                    }
                    $holder->forceFill(['active_reference' => null])->save();
                }

                $s->forceFill(self::attributes($data) + [
                    'courier_account_id' => $account->getKey(),
                    'courier' => $account->courier,
                    'state' => 'draft',
                    'client_reference' => $reference,
                    'active_reference' => $reference,
                    'error_code' => null,
                    'error_message' => null,
                    'needs_attention' => false,
                    'attention_reason' => null,
                ])->save();

                return $s->setRelation('courierAccount', $account);
            }));
        } catch (UniqueConstraintViolationException) {
            $holder = $this->liveHolder(null, (string) (($data['client_reference'] ?? null) ?: $shipment->client_reference));

            throw $holder !== null ? $this->duplicate($holder) : new ApiError(409, 'active_shipment_exists', 'A live shipment already uses this client_reference.');
        }

        $this->rememberCustomer($updated, false);

        return $updated;
    }

    /**
     * Saves the recipient as a customer once the shipment is committed (at once when no transaction is open). A
     * failure is logged and never reaches the booking. `$count`: false for an edited draft, already counted.
     */
    private function rememberCustomer(Shipment $shipment, bool $count): void
    {
        DB::afterCommit(function () use ($shipment, $count) {
            try {
                app(Customers::class)->upsertFromShipment($shipment, $count);
            } catch (Throwable $e) {
                Log::warning('Saving the customer failed', ['shipment_id' => $shipment->id, 'exception' => $e::class]);
            }
        });
    }

    /**
     * Moves a draft to queued and books it. Anything but a draft is refused: submitting twice must not book twice.
     * With $now (dashboard requests: no queue worker runs) the booking runs in this request and the shipment comes
     * back with its outcome: booked, failed, or unknown with attention. `now: true` callers must not hold a transaction.
     *
     * @throws ApiError 409 not_draft, 422 account_misconfigured
     * @throws LogicException `now: true` inside an open transaction
     */
    public function submit(Shipment $draft, bool $now = false): Shipment
    {
        if ($now) {
            self::assertNoTransaction();
        }

        $shipment = DB::transaction(function () use ($draft) {
            $s = Shipment::query()->whereKey($draft->getKey())->lockForUpdate()->firstOrFail();
            if ($s->state !== 'draft') {
                throw new ApiError(409, 'not_draft', 'Only a draft shipment can be submitted.', false, ['state' => [$s->state]]);
            }

            $account = $s->courierAccount ?? throw new ApiError(422, 'account_misconfigured', 'This shipment has no courier account.');
            AccountResolver::assertBookable($account);

            $s->forceFill(['state' => 'queued'])->save();

            return $s;
        });

        if ($now) {
            return $this->bookNow($shipment->id);
        }

        BookShipment::dispatch($shipment->id)->afterCommit();

        return $shipment;
    }

    /**
     * Runs BookShipment in this request, so its lock and state guards are the only double-booking protection. The
     * sync queue calls the job's failed() before rethrowing, exactly as a worker would, so an exception that escapes
     * the job has already ended in `unknown` (or left a never-started booking `queued`); it is logged here and never
     * reaches the caller. Only reached outside any transaction (assertNoTransaction), so the read below sees the outcome.
     */
    private function bookNow(int $shipmentId): Shipment
    {
        try {
            BookShipment::dispatchSync($shipmentId);
        } catch (Throwable $e) {
            Log::error('Synchronous booking threw', ['shipment_id' => $shipmentId, 'exception' => $e::class]);
        }

        return Shipment::query()->with('courierAccount')->findOrFail($shipmentId);
    }

    /**
     * A synchronous courier call must not run inside a transaction, and its outcome must be committed before it is read.
     * Uses the transactions the after-commit machinery counts, so a test's RefreshDatabase wrapper is not one.
     *
     * @throws LogicException
     */
    public static function assertNoTransaction(): void
    {
        if (app('db.transactions')->callbackApplicableTransactions()->isNotEmpty()) {
            throw new LogicException('A synchronous courier call cannot run inside an open transaction.');
        }
    }

    /**
     * The shipment columns for client input, without account, app or state. Also used to build an unsaved shipment
     * for a quote or a coverage check, so those describe exactly what would be booked.
     *
     * @param  array<string, mixed>  $data  validated input
     * @return array<string, mixed>
     */
    public static function attributes(array $data): array
    {
        $payment = $data['payment'] ?? [];
        $cod = ($payment['method'] ?? null) === 'cod';
        $items = self::items($data['items'] ?? []);
        $parcel = self::pick($data['parcel'] ?? [], ['type', 'weight_kg', 'length_cm', 'width_cm', 'height_cm', 'category']);

        return [
            'method' => $data['method'] ?? 'pickup',
            'service' => $data['service'] ?? null,
            'options' => array_filter([
                'parcel' => $parcel === [] ? null : $parcel,
                'pickup_date' => $data['pickup_date'] ?? null,
                'pickup_slot' => $data['pickup_slot'] ?? null,
                'shipping_payment' => $payment['shipping_payment'] ?? null,
                // Insurance is opt-in: stored as an explicit boolean so the couriers never fall back to a default.
                'insure' => filter_var($data['insure'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ], fn ($v) => $v !== null),
            'pickup' => isset($data['pickup']) ? self::party($data['pickup'], ['contact_name', 'contact_phone'], 'contact_phone') : null,
            'recipient' => isset($data['recipient']) ? self::party($data['recipient'], ['first_name', 'last_name', 'phone', 'email'], 'phone') : null,
            'courier_note' => $data['note'] ?? null,
            'items' => $items,
            'items_count' => array_sum(array_column($items, 'quantity')),
            'items_summary' => self::summary($items),
            'payment_method' => $cod ? 'cod' : 'prepaid',
            'payment_state' => $cod ? 'cod_unpaid' : 'prepaid',
            'cod_amount' => $cod ? self::money($payment['cod_amount'] ?? null) : null,
            'declared_value' => self::money($payment['declared_value'] ?? null),
            'total' => self::money($data['total'] ?? null),
            'subtotal' => self::money($data['subtotal'] ?? null),
            'metadata' => $data['metadata'] ?? null,
        ];
    }

    /** "+63 917 123 4567", "639171234567" and "9171234567" are all stored as "09171234567". */
    public static function localPhone(?string $raw): ?string
    {
        $phone = PhPhone::normalize($raw);

        return $phone !== null && strlen($phone) === 12 ? '0'.substr($phone, 2) : $phone;
    }

    /**
     * The insert transaction, safe against concurrent creates of the same reference. On MySQL the loser of that race
     * may get a deadlock (1213, on the holder lookup's gap lock) or a unique violation instead of seeing the winner;
     * either way the transaction is gone. The live holder is then looked up again outside it: if there is one, the
     * answer is the usual 409. Otherwise the whole transaction is retried, at most CONCURRENCY_ATTEMPTS times.
     *
     * Inside a caller's transaction (DeadlockException: MySQL has rolled that back too) it is never retried here.
     */
    private function storeHoldingReference(?ClientApp $app, array $attributes): Shipment
    {
        return $this->withManualReferenceLock($app, $attributes['active_reference'], fn () => $this->storeHoldingReferenceUnlocked($app, $attributes));
    }

    /**
     * Manual shipments have no client app, and the unique index treats NULL apps as distinct, so two concurrent claims
     * of one manual reference are serialised here. A claim that cannot get the lock is answered like a live holder.
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    private function withManualReferenceLock(?ClientApp $app, ?string $reference, Closure $work): mixed
    {
        if ($app !== null || $reference === null) {
            return $work();
        }

        $lock = Cache::lock('shipment-ref:manual:'.$reference, 10);
        try {
            $lock->block(5);
        } catch (LockTimeoutException) {
            throw new ApiError(409, 'active_shipment_exists', 'A live shipment already uses this client_reference.');
        }

        try {
            return $work();
        } finally {
            $lock->release();
        }
    }

    private function storeHoldingReferenceUnlocked(?ClientApp $app, array $attributes): Shipment
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(function () use ($app, $attributes) {
                    $shipment = $this->insertHoldingReference($app, $attributes);
                    $shipment->forceFill(['courier_ref' => $this->courierRef($shipment)])->save();

                    return $shipment;
                });
            } catch (Throwable $e) {
                if (! $this->lostReferenceRace($e)) {
                    throw $e;
                }

                $holder = $this->liveHolder($app, $attributes['active_reference']);
                if ($holder !== null) {
                    throw $this->duplicate($holder);
                }

                if ($e instanceof DeadlockException || $attempt >= self::CONCURRENCY_ATTEMPTS) {
                    throw $e;
                }
            }
        }
    }

    private function lostReferenceRace(Throwable $e): bool
    {
        return $e instanceof UniqueConstraintViolationException
            || $e instanceof DeadlockException
            || ($e instanceof QueryException && app(ConcurrencyErrorDetector::class)->causedByConcurrencyError($e));
    }

    private function liveHolder(?ClientApp $app, string $reference): ?Shipment
    {
        return Shipment::query()
            ->where('client_app_id', $app?->getKey())
            ->where('active_reference', $reference)
            ->whereNotIn('state', self::RELEASED_STATES)
            ->first();
    }

    /**
     * Inserts the shipment holding its reference. A live holder is a 409; a terminal holder that still has the
     * reference (a state written by anything but the booking job or the canceller) gives it up first.
     */
    private function insertHoldingReference(?ClientApp $app, array $attributes): Shipment
    {
        for ($attempt = 1; ; $attempt++) {
            $holder = Shipment::query()
                ->where('client_app_id', $app?->getKey())
                ->where('active_reference', $attributes['active_reference'])
                ->lockForUpdate()
                ->first();

            if ($holder !== null) {
                if (! in_array($holder->state, self::RELEASED_STATES, true)) {
                    throw $this->duplicate($holder);
                }
                $holder->forceFill(['active_reference' => null])->save();
            }

            try {
                // Savepoint: losing the unique race must not poison the surrounding transaction.
                return DB::transaction(fn () => Shipment::query()->create($attributes));
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= 2) {
                    $winner = Shipment::query()
                        ->where('client_app_id', $app?->getKey())
                        ->where('active_reference', $attributes['active_reference'])
                        ->first();
                    throw $winner !== null ? $this->duplicate($winner) : $e;
                }
            }
        }
    }

    private function duplicate(Shipment $holder): ApiError
    {
        return new ApiError(409, 'active_shipment_exists', 'A live shipment already uses this client_reference.', false, [
            'shipment' => $holder->public_id,
        ]);
    }

    private function courierRef(Shipment $s): string
    {
        $prefix = (string) $this->settings->get('shipments.reference_prefix', 'VS');

        return $prefix.str_pad((string) $s->getKey(), self::REF_PAD, '0', STR_PAD_LEFT);
    }

    /**
     * Only the address keys the API defines are stored; anything else a client sends is dropped.
     *
     * @param  array<string, mixed>  $party
     * @param  list<string>  $own
     * @return array<string, mixed>
     */
    private static function party(array $party, array $own, string $phoneKey): array
    {
        $keys = [...$own, 'address_1', 'address_2', 'barangay', 'district', 'city', 'state', 'postcode', 'country'];
        $out = self::pick($party, $keys);
        $out[$phoneKey] = self::localPhone($party[$phoneKey] ?? null);
        $out['country'] = $out['country'] ?? 'PH';
        $out['codes'] = self::pick(is_array($party['codes'] ?? null) ? $party['codes'] : [], ['state', 'city', 'barangay']);

        return $out;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return list<array{name: string, quantity: int, price: string|null, weight_kg: float|null}>
     */
    private static function items(array $items): array
    {
        return array_values(array_map(fn (array $i) => [
            'name' => (string) $i['name'],
            'quantity' => (int) $i['quantity'],
            'price' => self::money($i['price'] ?? null),
            'weight_kg' => isset($i['weight_kg']) ? (float) $i['weight_kg'] : null,
        ], $items));
    }

    /** "Blue Tee ×2, Cap ×1", cut at 255 characters. */
    private static function summary(array $items): ?string
    {
        if ($items === []) {
            return null;
        }

        $text = implode(', ', array_map(fn (array $i) => "{$i['name']} ×{$i['quantity']}", $items));

        return mb_strlen($text) > self::ITEMS_SUMMARY_MAX ? mb_substr($text, 0, self::ITEMS_SUMMARY_MAX - 1).'…' : $text;
    }

    /** A validated amount as an exact two-decimal string; floats never touch the stored value. */
    private static function money(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $text = is_float($value) ? sprintf('%.2F', $value) : ltrim(trim((string) $value), '+');
        $negative = str_starts_with($text, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($text, '-'), 2), 2, '');
        $whole = ltrim($whole, '0') === '' ? '0' : ltrim($whole, '0');

        return ($negative ? '-' : '').$whole.'.'.str_pad(substr($fraction, 0, 2), 2, '0');
    }

    /** @return array<string, mixed> the listed keys present in $from, nulls kept */
    private static function pick(array $from, array $keys): array
    {
        return array_intersect_key($from, array_flip($keys));
    }
}
