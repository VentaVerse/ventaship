<?php

namespace App\Fulfillment;

use App\Couriers\Spx\SpxFulfillment;
use App\Couriers\Support\CourierRefusal;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Shipments\BookingOutcomes;
use App\Shipments\Canceller;
use App\Shipments\CourierFormFields;
use App\Shipments\ItemsText;
use App\Shipments\TrackingLink;
use App\Support\Money;
use App\Couriers\Support\PhPhone;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * What the shipment detail page shows, built once from a loaded shipment (clientApp, courierAccount, events newest
 * first). Rows without a value are left out; the view only lays them out.
 */
final class ShipmentDetail
{
    private const ZONE = 'Asia/Manila';

    private const ENVIRONMENTS = ['sandbox' => 'Sandbox', 'production' => 'Production'];

    public function __construct(public readonly Shipment $shipment) {}

    public static function at(?CarbonInterface $t): ?string
    {
        return $t?->copy()->timezone(self::ZONE)->format('M j, Y · g:i A');
    }

    /** The page heading: the order number, else the courier reference; never the public or database id. */
    public function title(): string
    {
        return (string) ($this->shipment->client_reference ?: $this->shipment->courier_ref ?: 'Shipment');
    }

    public function courierName(): string
    {
        $courier = (string) ($this->shipment->courier ?: $this->shipment->courierAccount?->courier);

        return $courier !== '' ? Couriers::name($courier) : 'The courier';
    }

    public function source(): string
    {
        return $this->shipment->source === 'manual' ? 'Manual' : ShipmentList::sourceLabel($this->shipment);
    }

    /** The courier's tracking page, only when it is an http(s) link. */
    public function trackingUrl(): ?string
    {
        if ($this->shipment->tracking_number === null) {
            return null;
        }
        $url = TrackingLink::for($this->shipment);

        return is_string($url) && preg_match('#^https?://#i', $url) ? $url : null;
    }

    public function recipient(): ?array
    {
        return is_array($this->shipment->recipient) ? $this->shipment->recipient : null;
    }

    public function name(): ?string
    {
        $r = $this->recipient();
        $name = $r ? trim(((string) ($r['first_name'] ?? '')).' '.((string) ($r['last_name'] ?? ''))) : '';

        return $name !== '' ? $name : null;
    }

    public function phone(): ?string
    {
        $phone = trim((string) ($this->recipient()['phone'] ?? ''));

        return $phone !== '' ? $phone : null;
    }

    /** The phone as people read it, "0917 123 4567" (PhPhone::shown). */
    public function phoneShown(): ?string
    {
        return PhPhone::shown($this->phone());
    }

    public function address(): ?string
    {
        $r = $this->recipient() ?? [];
        $parts = collect(['address_1', 'address_2', 'barangay', 'district', 'city', 'state', 'postcode'])
            ->map(fn ($k) => is_scalar($r[$k] ?? null) ? trim((string) $r[$k]) : '')
            ->filter(fn ($v) => $v !== '');

        return $parts->isEmpty() ? null : $parts->implode(', ');
    }

    public function note(): ?string
    {
        $note = trim((string) $this->shipment->courier_note);

        return $note !== '' ? $note : null;
    }

    /**
     * The Parcel card's rows with a value, label => text. Payment is a badge, added by the view.
     *
     * @return array<string, string>
     */
    public function parcelRows(): array
    {
        $s = $this->shipment;
        $parcel = is_array($s->options['parcel'] ?? null) ? $s->options['parcel'] : [];
        $size = collect([$parcel['length_cm'] ?? null, $parcel['width_cm'] ?? null, $parcel['height_cm'] ?? null])->filter()->implode(' × ');
        $value = $s->total ?? $s->declared_value;
        $cod = ShipmentBadges::isCod($s);

        return array_filter([
            'Item' => ItemsText::join($s->items) ?: null,
            'Packaging' => $this->packaging(isset($parcel['type']) ? (string) $parcel['type'] : null),
            'Weight' => isset($parcel['weight_kg']) && $parcel['weight_kg'] !== '' ? $parcel['weight_kg'].' kg' : null,
            'Size' => $size !== '' ? $size.' cm' : null,
            'Value' => $this->peso($value),
            'COD amount' => $cod ? $this->peso($s->cod_amount) : null,
            'Declared value' => $s->declared_value !== null && (string) $s->declared_value !== (string) $value ? $this->peso($s->declared_value) : null,
        ], fn ($v) => $v !== null && $v !== '');
    }

    /**
     * "Book this shipment?" for a stored draft: the same rows the form's dialog shows (resources/js/bookCheck.js),
     * as [label, value] pairs.
     *
     * @return list<array{0: string, 1: string}>
     */
    public function bookingSummary(): array
    {
        $s = $this->shipment;
        $courier = (string) $s->courier;
        $options = is_array($s->options) ? $s->options : [];
        $parcel = is_array($options['parcel'] ?? null) ? $options['parcel'] : [];
        $num = fn (mixed $v): string => CourierRefusal::number(is_float($v) ? rtrim(rtrim(sprintf('%.6F', $v), '0'), '.') : (string) $v);
        $pickup = $courier === 'spx' && ($s->method === 'pickup' || ($options['pickup_slot'] ?? null) !== null);
        $sides = [$parcel['length_cm'] ?? null, $parcel['width_cm'] ?? null, $parcel['height_cm'] ?? null];
        $place = collect([$this->recipient()['city'] ?? '', $this->recipient()['state'] ?? ''])->map(fn ($v) => trim((string) $v))->filter()->implode(', ');
        $payer = CourierFormFields::SHIPPING_PAYMENT[$courier][(string) ($options['shipping_payment'] ?? '')] ?? null;

        $rows = [
            ['Courier', $courier === 'spx' ? $this->courierName().' · '.CourierFormFields::HANDOVER[$pickup ? 'pickup' : 'dropoff'] : $this->courierName()],
            ['Pickup', $pickup ? (string) SpxFulfillment::describeSlot($options['pickup_slot'] ?? null) : ''],
            ['To', collect([$this->name(), $place])->filter()->implode(' · ')],
            ['Parcel', collect([
                $this->packaging(isset($parcel['type']) ? (string) $parcel['type'] : null),
                is_numeric($parcel['weight_kg'] ?? null) ? $num($parcel['weight_kg']).' kg' : null,
                collect($sides)->every(fn ($v) => is_numeric($v)) ? collect($sides)->map($num)->implode(' × ').' cm' : null,
            ])->filter()->implode(' · ')],
            ['COD', ShipmentBadges::isCod($s) ? (string) $this->peso($s->cod_amount) : ''],
            ['Shipping fee', (string) $payer],
        ];

        return array_values(array_filter($rows, fn (array $r) => trim($r[1]) !== ''));
    }

    public function insured(): string
    {
        return ($this->shipment->options['insure'] ?? false) ? 'Yes' : 'No';
    }

    /**
     * The Courier card's rows with a value. The account label is left out when it only repeats the courier's name.
     *
     * @return array<string, string>
     */
    public function courierRows(): array
    {
        $s = $this->shipment;
        $account = $s->courierAccount;
        $name = $this->courierName();
        $label = trim((string) $account?->label);
        $courier = collect([
            $s->courier || $account ? $name : null,
            $label !== '' && strcasecmp($label, $name) !== 0 ? $label : null,
            $account ? (self::ENVIRONMENTS[$account->mode] ?? null) : null,
        ])->filter()->implode(' · ');
        $pickup = is_array($s->pickup) ? trim((string) ($s->pickup['contact_name'] ?? '')) : '';

        return array_filter([
            'Courier' => $courier,
            'Service' => $s->service ? Str::title(str_replace('_', ' ', $s->service)) : null,
            'Pickup' => $pickup,
            'Pickup time' => $s->courier === 'spx' && $s->method === 'pickup' ? SpxFulfillment::describeSlot($s->options['pickup_slot'] ?? null) : null,
            'Courier reference' => $s->courier_ref,
            'Tracking' => $s->tracking_number,
            'Booked at' => self::at($s->booked_at),
            'Fee' => $this->peso($s->fee_amount),
            'Actual fee' => $this->peso($s->actual_fee),
        ], fn ($v) => $v !== null && $v !== '');
    }

    /**
     * The courier's proof-of-delivery photos (SPX: the tracking webhook's proof_of_delivery_list and the evidence
     * webhook's epod_list), stored as http(s) URLs only.
     *
     * @return list<string>
     */
    public function proofOfDelivery(): array
    {
        $urls = data_get($this->shipment->meta, 'proofs.epod');

        return is_array($urls)
            ? array_values(array_filter($urls, fn ($u) => is_string($u) && preg_match('#^https?://#i', $u) === 1))
            : [];
    }

    /**
     * The alert under the header: a refused booking (bad) with the courier's words, an unconfirmed booking (attn,
     * with the resolve form), or any other attention reason. One box; a second reason is a second line in it.
     *
     * @return array{kind: 'failed'|'unknown'|'attention', title: ?string, lines: list<string>}|null
     */
    public function alert(): ?array
    {
        $s = $this->shipment;
        $reason = trim((string) $s->attention_reason);

        if ($s->state === 'failed' && trim((string) $s->error_message) !== '') {
            $lines = [trim((string) $s->error_message)];
            if ($reason !== '' && $reason !== $lines[0]) {
                $lines[] = $reason;
            }

            return [
                'kind' => 'failed',
                'title' => $s->error_code === 'courier_refused' ? "{$this->courierName()} refused this booking" : 'Booking failed',
                'lines' => $lines,
            ];
        }

        if ($s->state === 'unknown') {
            $since = $s->booking_started_at?->diffForHumans();

            return [
                'kind' => 'unknown',
                'title' => "{$this->courierName()} didn't confirm this booking".($since ? " · {$since}" : ''),
                'lines' => $reason !== '' && $reason !== BookingOutcomes::UNKNOWN_REASON ? [$reason] : [],
            ];
        }

        if ($reason !== '') {
            return ['kind' => 'attention', 'title' => null, 'lines' => [$reason]];
        }

        return null;
    }

    /**
     * Newest first. Repeats of one event (same time to the minute shown, status and label) show once; the shipment's own booking and
     * arrival close the list, so it is never empty.
     *
     * @return list<array{at: string, label: string, phase: ?string}>
     */
    public function timeline(): array
    {
        $s = $this->shipment;
        $rows = [];

        // The same status reported twice within the minute shown (a poll and the booking itself, say) is one event.
        $events = $s->events->unique(fn (ShipmentEvent $e) => implode('|', [
            self::at($e->occurred_at), (string) $e->courier_status, (string) $e->label,
        ]));
        foreach ($events as $e) {
            $rows[] = [
                't' => $e->occurred_at?->getTimestamp() ?? 0, 'rank' => 0, 'at' => self::at($e->occurred_at) ?? '',
                'label' => $e->label ?: ($s->courier ? StatusFilter::label($s->courier, (string) $e->courier_status) : (string) $e->courier_status),
                'phase' => $e->phase ?: null,
            ];
        }

        // Pickup times SPX's booking chose or moved (an API order without one, a time past SPX's cutoff).
        $changes = data_get($s->meta, 'pickup_changes');
        foreach (is_array($changes) ? $changes : [] as $change) {
            $at = is_array($change) && is_string($change['at'] ?? null) ? self::parseTime($change['at']) : null;
            $label = is_array($change) && is_string($change['label'] ?? null) ? $change['label'] : null;
            if ($at === null || $label === null) {
                continue;
            }
            $verb = ($change['reason'] ?? null) === 'chosen' ? 'Pickup time set' : 'Pickup time moved';
            $rows[] = ['t' => $at->getTimestamp(), 'rank' => 2, 'at' => self::at($at) ?? '', 'label' => "{$verb} · {$label}", 'phase' => null];
        }

        if ($s->booked_at !== null) {
            $rows[] = ['t' => $s->booked_at->getTimestamp(), 'rank' => 1, 'at' => self::at($s->booked_at), 'label' => 'Booked · '.$this->courierName(), 'phase' => null];
        }

        usort($rows, fn ($a, $b) => [$b['t'], $a['rank']] <=> [$a['t'], $b['rank']]);

        // Arrival is always the first thing that happened, whatever a courier's clock says.
        $rows[] = ['at' => self::at($s->created_at) ?? '', 'label' => $this->arrival(), 'phase' => null];

        return array_map(fn ($r) => ['at' => $r['at'], 'label' => $r['label'], 'phase' => $r['phase']], $rows);
    }

    private static function parseTime(string $at): ?CarbonInterface
    {
        try {
            return \Carbon\CarbonImmutable::parse($at);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array{print: bool, edit: bool, delete: bool, book: bool, retry: bool, cancel: bool, resolve: bool} */
    public function actions(): array
    {
        $s = $this->shipment;
        $editable = $s->isEditable();

        return [
            'print' => $s->state === 'booked' && Route::has('shipments.labels'),
            'edit' => $editable && Route::has('shipments.edit'),
            'delete' => $editable && Route::has('shipments.destroy'),
            'book' => $s->state === 'draft' && Route::has('shipments.book'),
            // A failed manual shipment the form can edit is fixed and booked from there.
            'retry' => self::retryable($s) && ! $editable && Route::has('shipments.retry'),
            // What the bulk Cancel acts on; a draft is booked or deleted from here instead.
            'cancel' => Canceller::accepts($s) && $s->state !== 'draft' && Route::has('shipments.cancel'),
            'resolve' => $s->state === 'unknown' && Route::has('shipments.resolve'),
        ];
    }

    /** The rules ShipmentCreator::retry() applies, so the button only shows when it can work. */
    public static function retryable(Shipment $s): bool
    {
        return $s->state === 'failed' && $s->cancel_state === null && $s->pii_purged_at === null && is_array($s->recipient);
    }

    private function arrival(): string
    {
        return match ($this->shipment->source) {
            'manual' => 'Created',
            'csv' => 'Imported from CSV',
            default => 'Received from '.$this->source(),
        };
    }

    private function packaging(?string $type): ?string
    {
        if ($type === null || $type === '') {
            return null;
        }
        $account = $this->shipment->courierAccount;
        $options = $account ? CourierFormFields::parcelOptions($account) : [];
        foreach ($options as $o) {
            if ($o['value'] === $type) {
                return $o['label'];
            }
        }

        return Str::title(str_replace(['-', '_'], ' ', $type));
    }

    private function peso(mixed $v): ?string
    {
        return $v !== null && $v !== '' ? Money::peso((string) $v) : null;
    }
}
