<?php

namespace App\Couriers\Lbc;

use App\Services\Address\DeepAddressData;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Turns an address into what an LBC booking needs: LBC's PSGC code for the
 * barangay, whether LBC serves it, and the LBC area code (MMA, NLA, SLA,
 * VIS, MIN) used for Origin and DestinationCode.
 *
 * The pipeline is LBC's own: search the city, search the barangay inside
 * it, then validate the barangay's PSGC code. Validating by names is never
 * done, because a misspelt name answers "Not Serviceable", exactly like an
 * address LBC really does not cover.
 *
 * The area code comes from the rate category validateServicableArea
 * returns ("METRO MANILA AREA" is MMA). Only when that category is missing
 * or unrecognised does it fall back to the region, read from the PSGC code
 * or the province name, and the fallback is logged.
 */
class LbcAreaResolver
{
    /** Rate category text => area code. Matched as a substring of the upper-cased category. */
    public const CATEGORY_AREAS = [
        'METRO MANILA' => 'MMA',
        'NORTH LUZON' => 'NLA',
        'SOUTH LUZON' => 'SLA',
        'VISAYAS' => 'VIS',
        'MINDANAO' => 'MIN',
    ];

    /**
     * PSGC region (first two digits of a ten-digit code) => area code. The
     * fallback only: LBC has not published its province table, so regions
     * IV-B (MIMAROPA) and V (Bicol) under South Luzon are an assumption.
     */
    public const REGION_AREAS = [
        '01' => 'NLA', '02' => 'NLA', '03' => 'NLA', '14' => 'NLA',
        '04' => 'SLA', '05' => 'SLA', '17' => 'SLA',
        '06' => 'VIS', '07' => 'VIS', '08' => 'VIS',
        '09' => 'MIN', '10' => 'MIN', '11' => 'MIN', '12' => 'MIN', '15' => 'MIN', '16' => 'MIN', '19' => 'MIN',
        '13' => 'MMA',
    ];

    /** How long a matched barangay's PSGC code is remembered. */
    private const LOCATION_CACHE_DAYS = 7;

    public function __construct(private readonly LbcClient $client)
    {
    }

    public function resolve(?string $barangay, ?string $city, ?string $province): LbcArea
    {
        $barangay = trim((string) $barangay);
        $city = trim((string) $city);
        $province = trim((string) $province);

        $area = new LbcArea(inputBarangay: $barangay, inputCity: $city, inputProvince: $province);

        if ($barangay === '' || $city === '') {
            return $area->withProblem('The address needs a barangay and a city for LBC.');
        }

        $location = $this->locate($barangay, $city, $province);
        if (isset($location['problem'])) {
            return $this->withFallbackArea($area->withProblem($location['problem']));
        }
        [$cityRow, $barangayRow] = [$location['city'], $location['barangay']];

        $psgc = self::padPsgc($barangayRow['barangayId']);
        $area = $area->withLocation(
            psgcCode: $psgc,
            barangay: (string) $barangayRow['barangayName'],
            city: (string) ($barangayRow['cityName'] ?? $cityRow['cityName']),
            province: (string) ($barangayRow['provinceName'] ?? $cityRow['provinceName'] ?? ''),
            fullLocationName: (string) ($barangayRow['fullLocationName'] ?? ''),
        );

        $answer = $this->client->validateServiceableArea($psgc);
        if ($answer === null) {
            return $this->withFallbackArea($area->withProblem('LBC could not answer whether it serves this barangay.'));
        }

        $status = (string) ($answer['status'] ?? '');
        $category = $answer['rate_details']['category'] ?? null;
        $area = $area->withServiceability(
            serviceable: strcasecmp($status, 'Serviceable') === 0,
            status: $status,
            category: is_string($category) ? $category : null,
            rate: isset($answer['rate_details']['rate']) ? (float) $answer['rate_details']['rate'] : null,
        );

        $code = self::areaForCategory($area->category);
        if ($code !== null) {
            return $area->withAreaCode($code, LbcArea::FROM_SERVICEABILITY);
        }

        return $this->withFallbackArea($area);
    }

    /**
     * LBC's city and barangay rows for an address, from its fuzzy search.
     *
     * A match is remembered for a week: a barangay's PSGC code does not move,
     * and each booking and coverage check would otherwise spend two searches
     * on an answer it already has. Whether LBC serves the barangay is never
     * remembered here; that is asked again every time.
     *
     * @return array{city: array, barangay: array}|array{problem: string}
     */
    private function locate(string $barangay, string $city, string $province): array
    {
        $cacheKey = 'courier-fulfillment:lbc:psgc:' . sha1(implode('|', [
            self::normalizeName($barangay), self::normalizeName($city), self::normalizeName($province),
        ]));

        $cached = Cache::get($cacheKey);
        if (is_array($cached) && isset($cached['city'], $cached['barangay'])) {
            return $cached;
        }

        $cityRow = $this->matchCity($city, $province);
        if ($cityRow === false) {
            return ['problem' => 'LBC could not answer its address search.'];
        }
        if ($cityRow === null) {
            return ['problem' => "LBC's address search found no city matching \"{$city}\"."];
        }

        $barangayRow = $this->matchBarangay($barangay, (int) $cityRow['cityId']);
        if ($barangayRow === false) {
            return ['problem' => 'LBC could not answer its address search.'];
        }
        if ($barangayRow === null) {
            return ['problem' => "LBC's address search found no barangay matching \"{$barangay}\" in {$cityRow['cityName']}."];
        }

        $found = ['city' => $cityRow, 'barangay' => $barangayRow];
        Cache::put($cacheKey, $found, now()->addDays(self::LOCATION_CACHE_DAYS));

        return $found;
    }

    /** The area code a rate category names, or null when it names none. */
    public static function areaForCategory(?string $category): ?string
    {
        $upper = strtoupper((string) $category);
        foreach (self::CATEGORY_AREAS as $needle => $code) {
            if ($upper !== '' && str_contains($upper, $needle)) {
                return $code;
            }
        }

        return null;
    }

    /** LBC returns PSGC codes as JSON numbers, losing leading zeros; codes are ten digits. */
    public static function padPsgc(mixed $code): string
    {
        return str_pad(preg_replace('/\D+/', '', (string) $code), 10, '0', STR_PAD_LEFT);
    }

    /** "City of Makati", "Makati City" and "MAKATI" all compare as MAKATI. */
    public static function normalizeName(string $name): string
    {
        $name = strtoupper(Str::ascii($name));
        $name = preg_replace('/[^A-Z0-9 ]+/', ' ', $name);
        $name = preg_replace('/\s+/', ' ', trim($name));
        $name = preg_replace('/^CITY OF /', '', $name);
        $name = preg_replace('/ CITY$/', '', $name);

        return trim($name);
    }

    /** @return array|null|false the one matching row, null for no single match, false when LBC did not answer */
    private function matchCity(string $city, string $province): array|null|false
    {
        $wanted = self::normalizeName($city);
        $rows = $this->client->searchCities($wanted);
        if ($rows === null) {
            return false;
        }

        $matches = array_values(array_filter($rows, fn ($r) => is_array($r)
            && isset($r['cityId'], $r['cityName'])
            && self::normalizeName((string) $r['cityName']) === $wanted));

        if (count($matches) > 1 && $province !== '') {
            $byProvince = array_values(array_filter($matches, fn ($r) => $this->sameProvince($province, (string) ($r['provinceName'] ?? ''))));
            $matches = $byProvince ?: $matches;
        }

        // Two cities of one name in different provinces, with no province to
        // tell them apart: guessing would book the wrong place.
        return count($matches) === 1 ? $matches[0] : null;
    }

    /** @return array|null|false the one matching row, null for no single match, false when LBC did not answer */
    private function matchBarangay(string $barangay, int $cityId): array|null|false
    {
        $wanted = self::normalizeName($barangay);
        $rows = $this->client->searchBarangays($wanted, $cityId);
        if ($rows === null) {
            return false;
        }

        $matches = array_values(array_filter($rows, fn ($r) => is_array($r)
            && isset($r['barangayId'], $r['barangayName'])
            && self::normalizeName((string) $r['barangayName']) === $wanted));

        return count($matches) === 1 ? $matches[0] : null;
    }

    private function sameProvince(string $ours, string $theirs): bool
    {
        $a = self::normalizeName($ours);
        $b = self::normalizeName($theirs);

        if ($this->isCapitalRegion($a) && $this->isCapitalRegion($b)) {
            return true;
        }

        return $a !== '' && ($a === $b || str_contains($b, $a) || str_contains($a, $b));
    }

    private function isCapitalRegion(string $normalized): bool
    {
        return str_contains($normalized, 'METRO MANILA')
            || str_contains($normalized, 'NCR')
            || str_contains($normalized, 'NATIONAL CAPITAL REGION');
    }

    /** Region-based area code, used only when serviceability gave no usable category. */
    private function withFallbackArea(LbcArea $area): LbcArea
    {
        $region = null;
        if ($area->psgcCode !== null) {
            $region = substr($area->psgcCode, 0, 2);
        } elseif ($this->isCapitalRegion(self::normalizeName($area->inputProvince))) {
            $region = '13';
        } else {
            $region = $this->regionOfProvince($area->inputProvince);
        }

        $code = $region !== null ? (self::REGION_AREAS[$region] ?? null) : null;

        Log::info('LBC area code from the region fallback', [
            'province' => $area->inputProvince,
            'region' => $region,
            'area' => $code,
            'category' => $area->category,
        ]);

        return $code !== null ? $area->withAreaCode($code, LbcArea::FROM_REGION) : $area;
    }

    /** The PSGC region of a province name, from the bundled address data. */
    private function regionOfProvince(string $province): ?string
    {
        $wanted = self::normalizeName($province);
        if ($wanted === '') {
            return null;
        }

        foreach (app(DeepAddressData::class)->level('PH', 'state') as $row) {
            if (self::normalizeName((string) $row['name']) === $wanted) {
                // Bundled codes are the nine-digit generation: RRPPMMBBB.
                return substr(str_pad((string) $row['code'], 9, '0', STR_PAD_LEFT), 0, 2);
            }
        }

        return null;
    }
}
