<?php

namespace App\Couriers\Support;

use App\Couriers\CourierOrder;

/**
 * An order's goods weight in kilograms: the order's own weight when VentaCart sent one, else the sum of its lines'
 * weights (each line's `weight_kg` is already per unit, converted by the extension from the product's unit).
 *
 * A prefill for the booking form, never a fact: packaging adds weight, and a product with no weight recorded adds
 * nothing. Null when no line has a weight at all, so the form asks rather than shows a zero.
 */
final class OrderWeight
{
    public static function kg(CourierOrder $order): ?float
    {
        if ($order->weight_kg !== null && $order->weight_kg > 0) {
            return round((float) $order->weight_kg, 2);
        }

        $total = 0.0;
        $any = false;

        foreach ($order->products as $line) {
            $weight = (float) ($line->weight_kg ?? 0);
            if ($weight <= 0) {
                continue;
            }

            $total += $weight * max(1, (int) $line->quantity);
            $any = true;
        }

        return $any ? round($total, 2) : null;
    }
}
