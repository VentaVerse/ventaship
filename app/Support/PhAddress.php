<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * The Philippine address hierarchy (PSGC) from database/data/ph: province > city > (Manila district >) barangay.
 * Each level is indexed once (by code and by parent) and cached by the file's mtime, so a changed file rebuilds itself.
 */
class PhAddress
{
    private const FILES = [
        'provinces' => 'provinces.json',
        'cities' => 'cities.json',
        'districts' => 'districts.json',
        'barangays' => 'barangays.json',
    ];

    /** @var array<string, array{byCode: array<string, array<string, string>>, byParent: array<string, list<array<string, string>>>}> */
    private static array $memo = [];

    /** @return list<array{code: string, name: string}> */
    public function provinces(): array
    {
        return $this->children('provinces', '');
    }

    /** @return list<array{code: string, name: string, zip: string}> */
    public function cities(string $provinceCode): array
    {
        return $this->children('cities', $provinceCode);
    }

    /** @return list<array{code: string, name: string}> */
    public function districts(string $cityCode): array
    {
        return $this->children('districts', $cityCode);
    }

    /** @return list<array{code: string, name: string}> */
    public function barangays(string $parentCode): array
    {
        return $this->children('barangays', $parentCode);
    }

    /** @return array<string, string>|null  The row with its parent: code, name, parent[, zip]. */
    public function find(string $level, string $code): ?array
    {
        return $this->table($level)['byCode'][$code] ?? null;
    }

    /** The child of a parent with this name (case-insensitive), for rows saved before codes existed. */
    public function matchName(string $level, string $parent, string $name): ?array
    {
        $name = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name)));
        if ($name === '') {
            return null;
        }
        foreach ($this->children($level, $parent) as $row) {
            if (mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $row['name']))) === $name) {
                return $row;
            }
        }

        return null;
    }

    /** Whether each code exists and hangs off the one before it. A Manila barangay goes through its district. */
    public function validChain(?string $state, ?string $city, ?string $district, ?string $barangay): bool
    {
        $state = $state ?: null;
        $city = $city ?: null;
        $district = $district ?: null;
        $barangay = $barangay ?: null;

        if ($state === null) {
            return $city === null && $district === null && $barangay === null;
        }
        if ($this->find('provinces', $state) === null) {
            return false;
        }
        if ($city === null) {
            return $district === null && $barangay === null;
        }
        if (($this->find('cities', $city)['parent'] ?? null) !== $state) {
            return false;
        }
        if ($district !== null && ($this->find('districts', $district)['parent'] ?? null) !== $city) {
            return false;
        }
        if ($barangay === null) {
            return true;
        }
        $needs = $this->districts($city) !== [];
        if ($needs && $district === null) {
            return false;
        }

        return ($this->find('barangays', $barangay)['parent'] ?? null) === ($district ?? $city);
    }

    /**
     * Whether a posted address block is honest. Codes are optional per level: every level that has a code must be
     * a valid chain and carry that code's name; a level without a code is accepted as free text (legacy data).
     *
     * @param  array<string, mixed>  $address
     */
    public function matchesPosted(array $address): bool
    {
        $codes = (array) ($address['codes'] ?? []);
        $state = (string) ($codes['state'] ?? '');
        $city = (string) ($codes['city'] ?? '');
        $barangay = (string) ($codes['barangay'] ?? '');
        $name = fn (string $f): string => trim((string) preg_replace('/\s+/u', ' ', (string) ($address[$f] ?? '')));

        $stateRow = $state !== '' ? $this->find('provinces', $state) : null;
        $cityRow = $city !== '' ? $this->find('cities', $city) : null;
        $brgyRow = $barangay !== '' ? $this->find('barangays', $barangay) : null;
        if (($state !== '' && ! $stateRow) || ($city !== '' && ! $cityRow) || ($barangay !== '' && ! $brgyRow)) {
            return false;
        }

        // A city's parent must be the posted province, a barangay's the posted city (or one of its districts).
        if ($cityRow && $state !== '' && $cityRow['parent'] !== $state) {
            return false;
        }
        $district = null;
        if ($brgyRow) {
            $parent = $brgyRow['parent'];
            if ($city !== '' && $parent !== $city) {
                $district = $this->find('districts', $parent);
                if (! $district || $district['parent'] !== $city) {
                    return false;
                }
            } elseif ($city === '') {
                // No city code to anchor it: the barangay's own ancestry must still be a real chain.
                $district = $this->find('districts', $parent);
                $cityCode = $district['parent'] ?? $parent;
                if ($this->find('cities', $cityCode) === null) {
                    return false;
                }
            }
        }

        if ($stateRow && $name('state') !== $stateRow['name']) {
            return false;
        }
        if ($cityRow && $name('city') !== $cityRow['name']) {
            return false;
        }
        if ($brgyRow && $name('barangay') !== $brgyRow['name']) {
            return false;
        }
        if ($district) {
            return $name('district') === $district['name'];
        }
        if ($cityRow && $this->districts($city) !== []) {
            // Districted city: the posted district, if any, must be one of its districts.
            return $name('district') === '' || $this->matchName('districts', $city, $name('district')) !== null;
        }

        return $cityRow === null || $name('district') === '';
    }

    /** @return list<array<string, string>> */
    private function children(string $level, string $parent): array
    {
        return $this->table($level)['byParent'][$parent] ?? [];
    }

    /** @return array{byCode: array<string, array<string, string>>, byParent: array<string, list<array<string, string>>>} */
    private function table(string $level): array
    {
        $file = self::FILES[$level] ?? throw new \InvalidArgumentException("Unknown address level [$level].");
        $path = database_path('data/ph/'.$file);
        $key = "ph-address.$level.".(string) filemtime($path);

        return self::$memo[$key] ??= Cache::rememberForever($key, fn () => $this->build($level, $path));
    }

    /** @return array{byCode: array<string, array<string, string>>, byParent: array<string, list<array<string, string>>>} */
    private function build(string $level, string $path): array
    {
        $byCode = [];
        $byParent = [];
        foreach (json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR) as $row) {
            $parent = (string) ($row['parent'] ?? '');
            $item = ['code' => (string) $row['code'], 'name' => (string) $row['name']];
            if ($level === 'cities') {
                $item['zip'] = (string) ($row['zip_code'] ?? '');
            }
            $byCode[$item['code']] = $item + ['parent' => $parent];
            $byParent[$parent][] = $item;
        }
        foreach ($byParent as &$list) {
            usort($list, fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));
        }

        return ['byCode' => $byCode, 'byParent' => $byParent];
    }
}
