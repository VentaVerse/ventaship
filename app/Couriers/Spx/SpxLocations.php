<?php

namespace App\Couriers\Spx;

use App\Support\PhAddress;

/**
 * SPX's own location list (database/data/spx/locations.json, compiled from SPX's xlsx by
 * `ventaship:spx-locations-compile`) and the mapping from a stored PSGC address onto it.
 *
 * SPX's four levels are not PSGC's: `state` is an island-group bucket (Metro Manila, North Luzon, South Luzon,
 * Visayas, Mindanao), `city` is the province ("Metro Manila" for NCR), `district` is the city/municipality (for the
 * City of Manila: its sub-district, "Ermita"), `street` is the barangay. SPX rejects any other combination with
 * NotSupportDeliverAddressErrorCode, so an address is only sent as a row that exists in the list, never guessed.
 *
 * Names are matched on a key: lowercase, accents and ñ folded, "City of"/"City" and punctuation dropped, common
 * abbreviations spelled out, and a trailing parenthetical ("(Capital)", "(Pob.)") ignored. Where PSGC and SPX spell
 * a province or municipality differently the aliases below say so; they come from diffing every PSGC province and
 * city against the SPX list.
 *
 * The index is compiled to storage/framework/cache/spx/locations-{mtimes}.php like App\Support\PhAddress.
 */
class SpxLocations
{
    /** PSGC province key => SPX province key. */
    private const PROVINCE_ALIASES = [
        'samar' => 'westernsamar',
        'davaooccidental' => 'davaodelsur',
    ];

    /** "PSGC province key|PSGC city key" => SPX district key, for municipalities SPX spells differently. */
    private const CITY_ALIASES = [
        'catanduanes|bagamanoc' => 'bayamanoc',
        'maguindanao|barira' => 'barrira',
        'cebu|cordova' => 'cordoba',
        'bohol|getafe' => 'jetafe',
        'nuevavizcaya|kasibu' => 'kasiba',
        'abra|lagangilang' => 'lagangilan',
        'easternsamar|lawaan' => 'lauaan',
        'sulu|lugus' => 'lungus',
        'misamisoccidental|ozamiz' => 'ozamis',
        'abra|licuanbaay' => 'licuan',
    ];

    private const ABBREVIATIONS = [
        'sto' => 'santo', 'sta' => 'santa', 'pob' => 'poblacion', 'brgy' => 'barangay', 'bgy' => 'barangay',
        'gen' => 'general', 'pres' => 'president', 'bry' => 'barangay', 'hen' => 'general',
    ];

    /** SPX flag positions in each row's flag string (the sheet's column order). */
    public const PICKUP = 0;

    public const COD_PICKUP = 1;

    public const DELIVER = 2;

    public const COD_DELIVER = 3;

    /** @var array<string, array{provinces: array<string, array{state: string, city: string}>, districts: array<string, array<string, string>>, streets: array<string, array<string, list<array{0: string, 1: string}>>>}> */
    private static array $memo = [];

    private readonly string $path;

    private readonly string $compiledDir;

    private readonly PhAddress $ph;

    public function __construct(?string $path = null, ?string $compiledDir = null, ?PhAddress $ph = null)
    {
        $this->path = $path ?? database_path('data/spx/locations.json');
        $this->compiledDir = $compiledDir ?? storage_path('framework/cache/spx');
        $this->ph = $ph ?? new PhAddress;
    }

    /**
     * The SPX location for a PSGC address block (state = province, city, district = Manila sub-district, barangay).
     *
     * @param  array<string, mixed>  $address
     * @return array{ok: true, location: array{state: string, city: string, district: string, street: string}, flags: string}|array{ok: false, missing: 'province'|'city'|'barangay'}
     */
    public function resolve(array $address): array
    {
        $index = $this->index();
        $province = self::key((string) ($address['state'] ?? ''));
        $province = self::PROVINCE_ALIASES[$province] ?? $province;
        if ($province === '' || ! isset($index['provinces'][$province])) {
            return ['ok' => false, 'missing' => 'province'];
        }

        $districts = $this->districtKeys($index, $province, (string) ($address['city'] ?? ''), (string) ($address['district'] ?? ''));
        if ($districts === []) {
            return ['ok' => false, 'missing' => 'city'];
        }

        $barangay = (string) ($address['barangay'] ?? '');
        $found = [];
        $siblings = null;
        foreach ($districts as $district) {
            $street = $this->street($index['streets'][$province.'|'.$district] ?? [], $barangay, $looseKey);
            if ($street === null) {
                continue;
            }
            // A loose match ("San Jose (Pob.)" as SPX's "San Jose") stands only when no other barangay of the same
            // PSGC city would reduce to it too ("Balogo (Bacon District)" / "Balogo (Sorsogon East District)").
            if ($looseKey !== null) {
                $siblings ??= $this->siblings($address);
                if ($siblings === null || array_filter($siblings, fn ($n) => $n !== trim($barangay) && in_array($looseKey, self::streetKeys($n), true)) !== []) {
                    continue;
                }
            }
            $found[] = [$district, $street];
        }
        // A Manila address without its sub-district is only placed when the barangay names one place.
        if (count($found) !== 1) {
            return ['ok' => false, 'missing' => 'barangay'];
        }
        [$district, [$street, $flags]] = $found[0];

        return ['ok' => true, 'location' => [
            'state' => $index['provinces'][$province]['state'],
            'city' => $index['provinces'][$province]['city'],
            'district' => $index['districts'][$province][$district],
            'street' => $street,
        ], 'flags' => $flags];
    }

    public static function supports(string $flags, int $flag): bool
    {
        return ($flags[$flag] ?? '0') === '1';
    }

    /**
     * The match key for a name: folded to lowercase ASCII letters and digits, with no spaces or punctuation.
     * $place also drops "City of" / "City" and a trailing parenthetical, for province and city names.
     */
    public static function key(string $name, bool $place = true): string
    {
        $name = mb_strtolower(trim($name));
        $name = str_replace(['ñ', 'Ñ'], 'n', $name);
        $folded = \Normalizer::normalize($name, \Normalizer::FORM_D);
        $name = (string) preg_replace('/\p{Mn}/u', '', $folded === false ? $name : $folded);
        $name = str_replace(["'", '’', '`'], '', $name);
        if ($place) {
            $name = (string) preg_replace('/(\s*\([^)]*\))+\s*$/', '', $name);
        }
        $tokens = preg_split('/[^a-z0-9]+/', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tokens = array_map(fn ($t) => self::ABBREVIATIONS[$t] ?? $t, $tokens);
        // "Bgy. No. 5" is "Barangay 5".
        foreach ($tokens as $i => $t) {
            if ($t === 'no' && ($tokens[$i - 1] ?? null) === 'barangay') {
                unset($tokens[$i]);
            }
        }
        $tokens = array_values($tokens);
        if ($place) {
            if (count($tokens) > 2 && $tokens[0] === 'city' && $tokens[1] === 'of') {
                $tokens = array_slice($tokens, 2);
            }
            if (count($tokens) > 1 && end($tokens) === 'city') {
                array_pop($tokens);
            }
        }

        return implode('', $tokens);
    }

    /** @return list<string> The SPX district keys a PSGC city (and Manila sub-district) can be. */
    private function districtKeys(array $index, string $province, string $city, string $district): array
    {
        $known = $index['districts'][$province];
        $cityKey = self::key($city);
        if ($cityKey === '') {
            return [];
        }
        // NCR: SPX lists Manila by sub-district and every other city by its own name.
        if ($province === 'metromanila' && $cityKey === 'manila') {
            if (trim($district) !== '') {
                $key = self::key($district);

                return isset($known[$key]) ? [$key] : [];
            }

            return array_values(array_intersect(array_keys($known), self::MANILA_DISTRICTS));
        }
        foreach ([self::CITY_ALIASES[$province.'|'.$cityKey] ?? null, $cityKey] as $key) {
            if ($key !== null && isset($known[$key])) {
                return [$key];
            }
        }

        return [];
    }

    private const MANILA_DISTRICTS = [
        'binondo', 'ermita', 'intramuros', 'malate', 'paco', 'pandacan', 'portarea', 'quiapo', 'sampaloc',
        'sanmiguel', 'sannicolas', 'santaana', 'santacruz', 'tondoiii',
    ];

    /**
     * The SPX street for a barangay: by its full key, then without a trailing parenthetical ("Barangay 1 (Pob.)").
     * Several SPX spellings of one key are the same place: an exact spelling wins, else the first.
     *
     * $looseKey is set to the key used when it was not the barangay's full name.
     *
     * @param  array<string, list<array{0: string, 1: string}>>  $streets
     * @return array{0: string, 1: string}|null
     */
    private function street(array $streets, string $barangay, ?string &$looseKey = null): ?array
    {
        $looseKey = null;
        $full = self::key($barangay, false);
        foreach (array_unique([$full, ...self::streetKeys($barangay)]) as $key) {
            if ($key === '' || ! isset($streets[$key])) {
                continue;
            }
            $looseKey = $key === $full ? null : $key;
            foreach ($streets[$key] as $row) {
                if (mb_strtolower($row[0]) === mb_strtolower(trim($barangay))) {
                    return $row;
                }
            }

            return $streets[$key][0];
        }

        return null;
    }

    /**
     * The looser keys a barangay name can be listed under: without its trailing parenthetical, and for
     * "Bgy. No. 1, San Lorenzo (Pob.)" its number alone, which is how SPX lists Laoag's.
     *
     * @return list<string>
     */
    private static function streetKeys(string $barangay): array
    {
        $keys = [self::key($barangay)];
        if (preg_match('/^([^,]*\d[\s-]*[a-z]?)\s*,/iu', trim($barangay), $m)) {
            $keys[] = self::key($m[1]);
        }

        return $keys;
    }

    /**
     * The barangay names of the address's PSGC city (or Manila district), by codes when it has them, else by
     * name; null when the address cannot be placed in PSGC.
     *
     * @param  array<string, mixed>  $address
     * @return list<string>|null
     */
    private function siblings(array $address): ?array
    {
        $codes = is_array($address['codes'] ?? null) ? $address['codes'] : [];
        $parent = null;
        if (($codes['barangay'] ?? '') !== '') {
            $parent = $this->ph->find('barangays', (string) $codes['barangay'])['parent'] ?? null;
        }
        if ($parent === null) {
            $province = $this->ph->matchName('provinces', '', (string) ($address['state'] ?? ''));
            $city = $province ? $this->ph->matchName('cities', $province['code'], (string) ($address['city'] ?? '')) : null;
            if ($city === null) {
                return null;
            }
            $parent = $city['code'];
            if ($this->ph->districts($city['code']) !== []) {
                $district = $this->ph->matchName('districts', $city['code'], (string) ($address['district'] ?? ''));
                if ($district === null) {
                    return null;
                }
                $parent = $district['code'];
            }
        }
        $names = array_column($this->ph->barangays($parent), 'name');

        return $names === [] ? null : $names;
    }

    /** @return array{provinces: array<string, array{state: string, city: string}>, districts: array<string, array<string, string>>, streets: array<string, array<string, list<array{0: string, 1: string}>>>} */
    private function index(): array
    {
        // Named by the data's and this class's mtimes: the keys are built by key(), so a change to either rebuilds.
        $compiled = $this->compiledDir.'/locations-'.(string) filemtime($this->path).'-'.(string) filemtime(__FILE__).'.php';

        return self::$memo[$compiled] ??= $this->load($compiled);
    }

    private function load(string $compiled): array
    {
        if (is_file($compiled)) {
            return require $compiled;
        }
        $index = $this->build();
        if (! is_dir($this->compiledDir)) {
            @mkdir($this->compiledDir, 0775, true);
        }
        $tmp = $compiled.'.'.bin2hex(random_bytes(6)).'.tmp';
        if (@file_put_contents($tmp, '<?php return '.var_export($index, true).';'.PHP_EOL) !== false && @rename($tmp, $compiled)) {
            foreach (glob($this->compiledDir.'/locations-*.php') ?: [] as $stale) {
                if ($stale !== $compiled) {
                    @unlink($stale);
                }
            }
        } else {
            @unlink($tmp);
        }

        return $index;
    }

    private function build(): array
    {
        $index = ['provinces' => [], 'districts' => [], 'streets' => []];
        foreach (json_decode((string) file_get_contents($this->path), true, 512, JSON_THROW_ON_ERROR) as [$state, $city, $district, $street, $flags]) {
            $p = self::key($city);
            $d = self::key($district);
            $index['provinces'][$p] ??= ['state' => $state, 'city' => $city];
            $index['districts'][$p][$d] ??= $district;
            $index['streets'][$p.'|'.$d][self::key($street, false)][] = [$street, $flags];
        }

        return $index;
    }
}
