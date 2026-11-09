<?php

namespace App\Fulfillment;

use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Shipments\BookingOutcomes;
use App\Shipments\Canceller;
use App\Shipments\CourierFormFields;
use App\Shipments\ItemsText;
use App\Shipments\TrackingLink;
use App\Support\Money;
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

    /** The page heading: the order's VS number, never the long public id. */
    public function title(): string
    {
        return (string) ($this->shipment->courier_ref ?: $this->shipment->client_reference ?: 'Shipment');
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
            'Tracking' => $s->tracking_number,
            'Booked at' => self::at($s->booked_at),
            'Fee' => $this->peso($s->fee_amount),
        ], fn ($v) => $v !== null && $v !== '');
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

        if ($s->booked_at !== null) {
            $rows[] = ['t' => $s->booked_at->getTimestamp(), 'rank' => 1, 'at' => self::at($s->booked_at), 'label' => 'Booked · '.$this->courierName(), 'phase' => null];
        }

        usort($rows, fn ($a, $b) => [$b['t'], $a['rank']] <=> [$a['t'], $b['rank']]);

        // Arrival is always the first thing that happened, whatever a courier's clock says.
        $rows[] = ['at' => self::at($s->created_at) ?? '', 'label' => $this->arrival(), 'phase' => null];

        return array_map(fn ($r) => ['at' => $r['at'], 'label' => $r['label'], 'phase' => $r['phase']], $rows);
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
