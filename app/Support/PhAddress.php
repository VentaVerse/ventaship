<?php

namespace App\Support;

/**
 * The Philippine address hierarchy (PSGC) from database/data/ph: province > city > (Manila district >) barangay.
 * Each level is indexed once (by code and by parent) and compiled to a PHP file named by the source's mtime, so
 * opcache keeps it in shared memory and a changed source rebuilds itself. The cache store is never used: the
 * barangays table is too big for a database cache row.
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

    private readonly string $dataDir;

    private readonly string $compiledDir;

    public function __construct(?string $dataDir = null, ?string $compiledDir = null)
    {
        $this->dataDir = $dataDir ?? database_path('data/ph');
        $this->compiledDir = $compiledDir ?? storage_path('framework/cache/ph');
    }

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
        return $this->postedMismatch($address) === null;
    }

    /**
     * The field a posted address block fails on, or null when it is honest: "district" when a districted city's
     * district does not match, otherwise "state".
     *
     * @param  array<string, mixed>  $address
     */
    public function postedMismatch(array $address): ?string
    {
        $codes = $address['codes'] ?? [];
        if (! is_array($codes)) {
            return 'state';
        }
        foreach ([[$codes, ['state', 'city', 'barangay']], [$address, ['state', 'city', 'district', 'barangay']]] as [$block, $keys]) {
            foreach ($keys as $key) {
                if (isset($block[$key]) && ! is_scalar($block[$key])) {
                    return 'state';
                }
            }
        }
        $state = (string) ($codes['state'] ?? '');
        $city = (string) ($codes['city'] ?? '');
        $barangay = (string) ($codes['barangay'] ?? '');
        $name = fn (string $f): string => trim((string) preg_replace('/\s+/u', ' ', (string) ($address[$f] ?? '')));

        $stateRow = $state !== '' ? $this->find('provinces', $state) : null;
        $cityRow = $city !== '' ? $this->find('cities', $city) : null;
        $brgyRow = $barangay !== '' ? $this->find('barangays', $barangay) : null;
        if (($state !== '' && ! $stateRow) || ($city !== '' && ! $cityRow) || ($barangay !== '' && ! $brgyRow)) {
            return 'state';
        }

        // A city's parent must be the posted province, a barangay's the posted city (or one of its districts).
        if ($cityRow && $state !== '' && $cityRow['parent'] !== $state) {
            return 'state';
        }
        $district = null;
        if ($brgyRow) {
            $parent = $brgyRow['parent'];
            if ($city !== '' && $parent !== $city) {
                $district = $this->find('districts', $parent);
                if (! $district || $district['parent'] !== $city) {
                    return 'state';
                }
            } elseif ($city === '') {
                // No city code to anchor it: the barangay's own ancestry must still be a real chain.
                $district = $this->find('districts', $parent);
                $cityCode = $district['parent'] ?? $parent;
                if ($this->find('cities', $cityCode) === null) {
                    return 'state';
                }
            }
        }

        if ($stateRow && $name('state') !== $stateRow['name']) {
            return 'state';
        }
        if ($cityRow && $name('city') !== $cityRow['name']) {
            return 'state';
        }
        if ($brgyRow && $name('barangay') !== $brgyRow['name']) {
            return 'state';
        }
        if ($district) {
            return $name('district') === $district['name'] ? null : 'district';
        }
        if ($cityRow && $this->districts($city) !== []) {
            // Districted city: the posted district, if any, must be one of its districts.
            return ($name('district') === '' || $this->matchName('districts', $city, $name('district')) !== null) ? null : 'district';
        }

        return ($cityRow === null || $name('district') === '') ? null : 'state';
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
        $path = $this->dataDir.'/'.$file;
        $compiled = $this->compiledDir."/$level-".(string) filemtime($path).'.php';

        return self::$memo[$compiled] ??= $this->load($level, $path, $compiled);
    }

    /** @return array{byCode: array<string, array<string, string>>, byParent: array<string, list<array<string, string>>>} */
    private function load(string $level, string $path, string $compiled): array
    {
        if (is_file($compiled)) {
            return require $compiled;
        }

        $table = $this->build($level, $path);
        if (! is_dir($this->compiledDir)) {
            @mkdir($this->compiledDir, 0775, true);
        }
        $tmp = $compiled.'.'.bin2hex(random_bytes(6)).'.tmp';
        if (@file_put_contents($tmp, '<?php return '.var_export($table, true).';'.PHP_EOL) !== false && @rename($tmp, $compiled)) {
            foreach (glob($this->compiledDir."/$level-*.php") ?: [] as $stale) {
                if ($stale !== $compiled) {
                    @unlink($stale);
                }
            }
        } else {
            @unlink($tmp);
        }

        return $table;
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
