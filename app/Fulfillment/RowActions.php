<?php

namespace App\Fulfillment;

use App\Models\Shipment;
use App\Shipments\Canceller;
use App\Shipments\LabelStore;
use Illuminate\Support\Facades\Route;

/**
 * What the list offers for one row, decided by the same rules the actions themselves apply: the bulk flags
 * (printable, bookable, cancellable) and the one next step of a row that needs the admin.
 */
final class RowActions
{
    /** @return array{printable: bool, bookable: bool, cancellable: bool} */
    public static function flags(Shipment $s): array
    {
        return [
            'printable' => LabelStore::printable($s),
            'bookable' => $s->state === 'draft',
            'cancellable' => Canceller::accepts($s),
        ];
    }

    /**
     * The next step of a row that needs action, or null.
     *
     * @return array{kind: 'book'|'retry'|'check', label: string, variant: string, href: ?string}|null
     */
    public static function nextStep(Shipment $s): ?array
    {
        if (! Views::needsAction($s)) {
            return null;
        }

        return match ($s->state) {
            'draft' => Route::has('shipments.book')
                ? ['kind' => 'book', 'label' => 'Book', 'variant' => 'primary', 'href' => null]
                : null,
            // A refused booking is fixed and booked again from the form; a shipment the form cannot edit opens its page.
            'failed' => ['kind' => 'retry', 'label' => 'Retry', 'variant' => 'primary', 'href' => $s->isEditable()
                ? route('shipments.edit', $s->public_id)
                : route('shipments.show', $s->public_id)],
            'unknown' => ['kind' => 'check', 'label' => 'Check', 'variant' => 'default', 'href' => route('shipments.show', $s->public_id)],
            default => null,
        };
    }
}
