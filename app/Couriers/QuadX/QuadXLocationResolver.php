<?php

namespace App\Couriers\QuadX;

/**
 * Reconciles Venta address names with QuadX's expected names.
 *
 * Venta and QuadX both use PSGC but DIFFERENT vintages, so the CODES never
 * align — QuadX resolves addresses by NAME strings instead. The only common
 * gap is QuadX's "City" suffix (Venta stores "Makati", QuadX expects
 * "Makati City"). This normalizer handles that conservatively; because booking
 * is a manual, one-order-at-a-time admin action, any residual mismatch surfaces
 * as a QuadX error the admin can correct on the order.
 *
 * Kept intentionally small — can later be upgraded to match against QuadX's
 * live /locations API if name drift becomes a problem.
 */
class QuadXLocationResolver
{
    /** Metro Manila cities QuadX lists WITHOUT a "City" suffix. */
    private const NCR_NO_SUFFIX = ['Manila', 'Pateros'];

    public function normalizeCity(?string $city, ?string $state): ?string
    {
        $city = trim((string) $city);
        if ($city === '') return $city;

        $isNcr = in_array(strtolower(trim((string) $state)), ['metro manila', 'ncr', 'national capital region'], true);

        if ($isNcr
            && !str_ends_with(strtolower($city), 'city')
            && !in_array($city, self::NCR_NO_SUFFIX, true)) {
            return $city . ' City';
        }

        return $city;
    }

    public function normalizeState(?string $state): ?string
    {
        $state = trim((string) $state);
        // QuadX uses "Metro Manila" for NCR.
        if (in_array(strtolower($state), ['ncr', 'national capital region'], true)) {
            return 'Metro Manila';
        }
        return $state;
    }
}
