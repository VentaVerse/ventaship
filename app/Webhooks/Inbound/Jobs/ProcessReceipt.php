<?php

namespace App\Webhooks\Inbound\Jobs;

use App\Couriers\CourierRegistry;
use App\Models\CourierAccount;
use App\Models\Shipment;
use App\Models\WebhookReceipt;
use App\Shipments\BookingOutcomes;
use App\Shipments\StatusRecorder;
use App\Support\AfterResponse;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use JsonException;
use Throwable;

/**
 * Turns one stored courier webhook into shipment events (base plan §3). Parsed by the account's courier
 * (parseWebhook), each event is matched within the account:
 *  1. by tracking number;
 *  2. by courier_ref = reference_id, only for a shipment still `booking` (SPX `creating` included).
 * A matched SPX `creating` shipment that the event gives a tracking number is booked through BookingOutcomes.
 * Every matched event is recorded by StatusRecorder (source `webhook`), which drops duplicates.
 *
 * Receipt outcome: `processed` when any event matched (or there were none), `unmatched` when none did, `failed`
 * when the body cannot be read. Any unmatched event relays the raw receipt when the account has a relay URL; if
 * the relay cannot be queued (or, run inline after the response, fails) the receipt becomes `relay_pending` and
 * ventaship:receipts-reprocess queues it again.
 * A bad receipt is settled as failed and never blocks the others; a database error is retried.
 */
class ProcessReceipt implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    // Below the default connection's 90-second retry_after, so a slow run is killed before a second worker gets it.
    public int $timeout = 60;

    private const LOCK_SECONDS = 300;

    private const STATUS_MAX = 100;

    private const REMARKS_MAX = 2000;

    /** @param  bool  $inline  run after the response, not by a queue worker: the relay runs the same way */
    public function __construct(public int $receiptId, public bool $inline = false)
    {
        $this->onQueue('inbound');
    }

    public function handle(StatusRecorder $recorder, BookingOutcomes $outcomes): void
    {
        // The reprocess command may queue a receipt whose first job is still running.
        $lock = Cache::lock("receipt:{$this->receiptId}", self::LOCK_SECONDS);
        if (! $lock->get()) {
            return;
        }

        try {
            $this->process($recorder, $outcomes);
        } finally {
            $lock->release();
        }
    }

    /** Out of tries: settled as failed so the reprocess command stops picking it up. */
    public function failed(?Throwable $e): void
    {
        Log::error('Courier webhook receipt could not be processed', [
            'receipt' => $this->receiptId,
            'exception' => $e === null ? null : $e::class,
        ]);

        WebhookReceipt::query()->whereKey($this->receiptId)->where('status', 'received')->update([
            'status' => 'failed',
            'error' => 'processing failed'.($e === null ? '' : ': '.class_basename($e)),
            'processed_at' => now(),
        ]);
    }

    private function process(StatusRecorder $recorder, BookingOutcomes $outcomes): void
    {
        $receipt = WebhookReceipt::query()->find($this->receiptId);
        if ($receipt === null || $receipt->status !== 'received') {
            return;
        }

        $account = $receipt->courier_account_id === null ? null : CourierAccount::withTrashed()->find($receipt->courier_account_id);
        if ($account === null) {
            $this->settle($receipt, 'failed', 'courier account no longer exists');

            return;
        }

        try {
            $payload = json_decode((string) $receipt->body, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->settle($receipt, 'failed', 'body is not valid JSON');

            return;
        }
        if (! is_array($payload)) {
            $this->settle($receipt, 'failed', 'body is not a JSON object');

            return;
        }

        try {
            $events = CourierRegistry::for($account)->parseWebhook($payload);
        } catch (Throwable $e) {
            Log::warning('Courier webhook could not be parsed', ['receipt' => $receipt->id, 'exception' => $e::class]);
            $this->settle($receipt, 'failed', 'parse error: '.class_basename($e));

            return;
        }

        $matched = 0;
        $unmatched = 0;
        foreach (is_array($events) ? $events : [] as $event) {
            $shipment = is_array($event) ? $this->match($account, $event) : null;
            $status = is_array($event) ? $this->text($event['status'] ?? null) : null;
            if ($shipment === null || $status === null) {
                $unmatched++;

                continue;
            }

            $this->record($recorder, $outcomes, $receipt, $shipment, $event, $status);
            $matched++;
        }

        if ($matched === 0 && $unmatched === 0) {
            $this->settle($receipt, 'processed', 'no events in payload');

            return;
        }

        $this->settle($receipt, $matched > 0 ? 'processed' : 'unmatched', null);

        // The raw body cannot be split: one unmatched event relays the whole delivery, as the courier sent it.
        if ($unmatched > 0 && filled($account->relay_url) && ! $account->trashed()) {
            if ($this->inline) {
                $this->relayAfterResponse($receipt->id);

                return;
            }

            try {
                app(Dispatcher::class)->dispatch(new RelayReceipt($receipt->id));
            } catch (Throwable $e) {
                // Not lost: ventaship:receipts-reprocess queues the relay again.
                Log::error('Courier webhook relay could not be queued', ['receipt' => $receipt->id, 'exception' => $e::class]);
                $receipt->forceFill(['status' => 'relay_pending'])->save();
            }
        }
    }

    /** A relay that fails here leaves the receipt `relay_pending`: ventaship:receipts-reprocess queues it again. */
    private function relayAfterResponse(int $id): void
    {
        AfterResponse::run("relay receipt {$id}", function () use ($id): void {
            try {
                app()->call([new RelayReceipt($id), 'handle']);
            } catch (Throwable $e) {
                Log::warning('Courier webhook relay failed; left for reprocess', ['receipt' => $id, 'exception' => $e::class]);
                WebhookReceipt::query()->whereKey($id)->whereIn('status', ['unmatched', 'processed'])->update(['status' => 'relay_pending']);
            }
        });
    }

    /** @param  array<string, mixed>  $event */
    private function match(CourierAccount $account, array $event): ?Shipment
    {
        $tracking = $this->text($event['tracking_number'] ?? null);
        if ($tracking !== null) {
            $s = Shipment::query()
                ->where('courier_account_id', $account->id)
                ->where('tracking_number', $tracking)
                ->orderByDesc('id')
                ->first();
            if ($s !== null) {
                return $s;
            }
        }

        $reference = $this->text($event['reference_id'] ?? null);
        if ($reference === null) {
            return null;
        }

        return Shipment::query()
            ->where('courier_account_id', $account->id)
            ->where('courier_ref', $reference)
            ->where('state', 'booking')
            ->orderByDesc('id')
            ->first();
    }

    /** @param  array<string, mixed>  $event */
    private function record(StatusRecorder $recorder, BookingOutcomes $outcomes, WebhookReceipt $receipt, Shipment $s, array $event, string $status): void
    {
        $tracking = $this->text($event['tracking_number'] ?? null);
        if ($s->state === 'booking' && $s->courier_status === 'creating' && $tracking !== null) {
            $outcomes->creationResolved($s->id, $tracking);
            $s->refresh();
        }

        $recorder->record(
            $s,
            mb_substr($status, 0, self::STATUS_MAX),
            $this->time($event['occurred_at'] ?? null, $receipt),
            'webhook',
            $this->remarks($event['remarks'] ?? null),
            $event,
            $receipt->id,
        );
    }

    private function settle(WebhookReceipt $receipt, string $status, ?string $error): void
    {
        $receipt->forceFill(['status' => $status, 'error' => $error, 'processed_at' => now()])->save();
    }

    /**
     * The courier's time, else when the receipt arrived: an event is never dropped for a missing or unreadable
     * timestamp, and a retry or reprocess of the same receipt lands on the same event key.
     */
    private function time(mixed $at, WebhookReceipt $receipt): CarbonImmutable
    {
        if (is_string($at) && $at !== '') {
            try {
                return CarbonImmutable::parse($at);
            } catch (Throwable) {
                // falls through
            }
        }

        return $receipt->received_at !== null ? CarbonImmutable::instance($receipt->received_at) : CarbonImmutable::now();
    }

    private function remarks(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, self::REMARKS_MAX) : null;
    }

    /** A short identifier or status; anything longer than a column holds is not one. */
    private function text(mixed $value): ?string
    {
        if (is_int($value)) {
            $value = (string) $value;
        }

        return is_string($value) && trim($value) !== '' && strlen($value) <= 255 ? trim($value) : null;
    }
}
