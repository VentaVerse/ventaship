<?php

namespace App\Couriers;

use App\Couriers\Contracts\Courier;
use App\Couriers\Support\ServiceabilityVerdict;
use App\Models\CourierAccount;
use Throwable;

/**
 * A scripted courier for tests: CourierRegistry::fake('quadx', FakeCourier::make()->booksAs('QX-1')).
 * It never makes a network call. Every courier call is recorded in $calls as ['method' => ..., 'args' => [...]].
 * Not final: a test may extend it to add an optional courier interface (e.g. ThrottlesStatusPolling).
 */
class FakeCourier implements Courier
{
    /** @var list<array{method: string, args: array<int, mixed>}> */
    public array $calls = [];

    private string $key = 'quadx';

    private ?string $trackingNumber = 'FAKE-0001';

    private string $bookedStatus = 'pending';

    private ?string $refusal = null;

    /** @var array<string, Throwable> method => what it throws */
    private array $throws = [];

    /** @var list<array{status: string, at?: int, remarks?: string|null}> */
    private array $events = [];

    private bool $cancelOk = true;

    /** @var array<string, mixed>|null resolvePendingCreation()'s scripted answer */
    private ?array $pendingAnswer = null;

    private ?string $pdf = null;

    private ?string $labelUrl = null;

    /** @var array<string, mixed>|null a raw printLabel() answer, returned as is */
    private ?array $labelAnswer = null;

    /** @var array<string, mixed> extra keys merged into a successful createShipment() answer */
    private array $bookingExtra = [];

    /** The registry signature, so the fake can stand in wherever a real courier is built. */
    public function __construct(?CourierAccount $account = null, ?AccountSettings $settings = null) {}

    public static function make(string $key = 'quadx'): static
    {
        $fake = new static();
        $fake->key = $key;

        return $fake;
    }

    public function booksAs(string $tn, string $status = 'pending'): self
    {
        $this->trackingNumber = $tn;
        $this->bookedStatus = $status;
        $this->refusal = null;

        return $this;
    }

    /** Extra keys on a successful booking answer, e.g. ['notice' => ..., 'attention_reason' => ...]. */
    public function booksWithExtra(array $extra): self
    {
        $this->bookingExtra = $extra;

        return $this;
    }

    public function refusesWith(string $msg): self
    {
        $this->refusal = $msg;

        return $this;
    }

    /** Throw from one courier method (createShipment by default) instead of answering. */
    public function throws(Throwable $e, string $method = 'createShipment'): self
    {
        $this->throws[$method] = $e;

        return $this;
    }

    /** @param  list<array{status: string, at?: int, remarks?: string|null}>  $events  oldest first */
    public function tracks(array $events): self
    {
        $this->events = array_values($events);

        return $this;
    }

    /** @param  array<string, mixed>|null  $answer  null while still pending */
    public function resolvesPendingAs(?array $answer): self
    {
        $this->pendingAnswer = $answer;

        return $this;
    }

    public function cancels(bool $ok = true): self
    {
        $this->cancelOk = $ok;

        return $this;
    }

    public function label(string $pdfBytes): self
    {
        $this->pdf = $pdfBytes;

        return $this;
    }

    /** printLabel() answers a provider link (as QuadX does) that the caller must download itself. */
    public function labelAt(string $url): self
    {
        $this->labelUrl = $url;

        return $this;
    }

    /** @param  array<string, mixed>  $answer */
    public function labelAnswer(array $answer): self
    {
        $this->labelAnswer = $answer;

        return $this;
    }

    /** @return list<array{method: string, args: array<int, mixed>}> the calls to one method */
    public function callsTo(string $method): array
    {
        return array_values(array_filter($this->calls, fn ($c) => $c['method'] === $method));
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getName(): string
    {
        return 'Test courier';
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function configurationIssues(): array
    {
        return [];
    }

    public function getServiceTypes(): array
    {
        return [['value' => 'standard', 'label' => 'Standard']];
    }

    public function getParcelOptions(): array
    {
        return [['value' => 'box', 'label' => 'Box']];
    }

    public function getShippingPaymentOptions(): array
    {
        return [['value' => 'net_off', 'label' => 'Bill me']];
    }

    public function createShipment(CourierOrder $order, array $opts): array
    {
        $this->record(__FUNCTION__, [$order, $opts]);

        if ($this->refusal !== null) {
            return ['success' => false, 'error' => $this->refusal];
        }

        return $this->bookingExtra + [
            'success' => true,
            'tracking_number' => $this->trackingNumber,
            'status' => $this->bookedStatus,
            'meta' => ['fake' => true, 'reference_id' => $order->reference],
        ];
    }

    public function cancelShipment(string $trackingNumber): array
    {
        $this->record(__FUNCTION__, [$trackingNumber]);

        return $this->cancelOk ? ['success' => true] : ['success' => false, 'error' => 'The test courier refused to cancel.'];
    }

    public function resolvePendingCreation(CourierOrder $order): ?array
    {
        $this->record(__FUNCTION__, [$order]);

        return $this->pendingAnswer;
    }

    public function getTracking(string $trackingNumber): array
    {
        $this->record(__FUNCTION__, [$trackingNumber]);

        if ($this->events === []) {
            return ['success' => true, 'status' => $this->bookedStatus, 'raw' => [], 'timeline' => []];
        }

        $last = $this->events[array_key_last($this->events)];

        return [
            'success' => true,
            'status' => $last['status'],
            'raw' => ['events' => $this->events],
            'timeline' => $this->events,
        ];
    }

    public function terminalStatuses(): array
    {
        return ['delivered', 'cancelled', 'returned'];
    }

    public function cancelableStatuses(): array
    {
        return ['pending', 'for_pickup'];
    }

    public function statusPhase(?string $status): array
    {
        $phase = match ((string) $status) {
            'delivered' => 'delivered',
            'canceled', 'cancelled' => 'cancelled',
            'returned' => 'returning',
            'failed_delivery' => 'failed',
            'picked_up', 'in_transit' => 'in_transit',
            default => 'to_handover',
        };

        return ['phase' => $phase, 'step' => 1, 'failed' => in_array($phase, ['failed', 'cancelled', 'returning'], true)];
    }

    public function estimateFee(CourierOrder $order, array $options): ?object
    {
        $this->record(__FUNCTION__, [$order, $options]);

        return null;
    }

    public function checkServiceability(CourierOrder $order, PickupPoint $from): ServiceabilityVerdict
    {
        $this->record(__FUNCTION__, [$order, $from]);

        return ServiceabilityVerdict::serviceable();
    }

    public function getPickupSlots(CourierOrder $order): array
    {
        $this->record(__FUNCTION__, [$order]);

        return [];
    }

    /** With label() scripted: `content` carries the PDF bytes and `url` a placeholder that is never fetched. */
    public function printLabel(string $trackingNumber, string $format): array
    {
        $this->record(__FUNCTION__, [$trackingNumber, $format]);

        if ($this->labelAnswer !== null) {
            return $this->labelAnswer;
        }
        if ($this->labelUrl !== null) {
            return ['success' => true, 'url' => $this->labelUrl, 'manifest_url' => null];
        }
        if ($this->pdf === null) {
            return ['success' => false, 'error' => 'The test courier has no label.'];
        }

        return [
            'success' => true,
            'url' => 'https://fake-courier.invalid/labels/' . rawurlencode($trackingNumber) . '.pdf',
            'content' => $this->pdf,
        ];
    }

    public function parseWebhook(array $payload): array
    {
        return [];
    }

    public function settingsSchema(): array
    {
        return [];
    }

    public function trackingLinks(CourierOrder $order): array
    {
        return [];
    }

    public function testConnection(): array
    {
        $this->record(__FUNCTION__, []);

        return ['ok' => true, 'message' => 'Test courier connected.'];
    }

    public function refreshStatusLabels(): array
    {
        return ['ok' => true, 'labels' => []];
    }

    /** @param  array<int, mixed>  $args */
    private function record(string $method, array $args): void
    {
        $this->calls[] = ['method' => $method, 'args' => $args];

        if (isset($this->throws[$method])) {
            throw $this->throws[$method];
        }
    }
}
