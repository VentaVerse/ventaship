<?php

namespace Tests\Unit\Couriers;

use App\Couriers\Spx\SpxLocations;
use Tests\TestCase;

/** PSGC addresses placed on SPX's own location list (database/data/spx/locations.json). */
class SpxLocationsTest extends TestCase
{
    private function resolve(string $state, string $city, string $barangay, string $district = ''): array
    {
        return app(SpxLocations::class)->resolve(compact('state', 'city', 'district', 'barangay'));
    }

    private function at(string $state, string $city, string $district, string $street): array
    {
        return compact('state', 'city', 'district', 'street');
    }

    public function test_uat_lanes_map_exactly(): void
    {
        $lanes = [
            [['Metro Manila', 'Manila', 'Barangay 654', 'Intramuros'], $this->at('Metro Manila', 'Metro Manila', 'Intramuros', 'Barangay 654')],
            [['Metro Manila', 'Manila', 'Barangay 659', 'Ermita'], $this->at('Metro Manila', 'Metro Manila', 'Ermita', 'Barangay 659')],
            [['Metro Manila', 'Manila', 'Barangay 287', 'Binondo'], $this->at('Metro Manila', 'Metro Manila', 'Binondo', 'Barangay 287')],
            [['Metro Manila', 'Pasig', 'Pinagbuhatan'], $this->at('Metro Manila', 'Metro Manila', 'Pasig City', 'Pinagbuhatan')],
        ];
        foreach ($lanes as [$psgc, $spx]) {
            $result = $this->resolve(...$psgc);
            $this->assertTrue($result['ok'], implode(' / ', $psgc));
            $this->assertSame($spx, $result['location']);
            $this->assertSame('111100', $result['flags']);
        }
    }

    public function test_numbered_barangays_never_cross_match(): void
    {
        $this->assertSame('Barangay 659', $this->resolve('Metro Manila', 'Manila', 'Barangay 659', 'Ermita')['location']['street']);
        $this->assertSame('Barangay 659-A', $this->resolve('Metro Manila', 'Manila', 'Barangay 659-A', 'Ermita')['location']['street']);
        $this->assertFalse($this->resolve('Metro Manila', 'Manila', 'Barangay 659-B', 'Ermita')['ok']);
    }

    public function test_manila_without_sub_district_is_placed_by_its_unique_barangay(): void
    {
        $this->assertSame('Ermita', $this->resolve('Metro Manila', 'Manila', 'Barangay 659')['location']['district']);
        // A sub-district that does not hold the barangay is not overridden.
        $this->assertSame(['ok' => false, 'missing' => 'barangay'], $this->resolve('Metro Manila', 'Manila', 'Barangay 659', 'Binondo'));
    }

    public function test_provincial_address(): void
    {
        $this->assertSame(
            $this->at('Visayas', 'Antique', 'Pandan', 'Bagumbayan'),
            $this->resolve('Antique', 'Pandan', 'Bagumbayan')['location'],
        );
        // PSGC's "(Capital)" and province names SPX spells differently.
        $this->assertSame(
            $this->at('Mindanao', 'Davao Del Sur', 'Malita', 'Bito'),
            $this->resolve('Davao Occidental', 'Malita (Capital)', 'Bito')['location'],
        );
        $this->assertSame(
            $this->at('Visayas', 'Western Samar', 'Gandara', 'Adela Heights (Pob.)'),
            $this->resolve('Samar (Western Samar)', 'Gandara', 'Adela Heights (Pob.)')['location'],
        );
    }

    public function test_accents_and_city_suffixes_fold(): void
    {
        $this->assertSame(
            $this->at('Metro Manila', 'Metro Manila', 'Las Pinas City', 'Almanza Uno'),
            $this->resolve('Metro Manila', 'Las Piñas', 'Almanza Uno')['location'],
        );
        $this->assertSame('Cebu City', $this->resolve('Cebu', 'Cebu City (Capital)', 'Lahug (Pob.)')['location']['district']);
    }

    public function test_poblacion_is_resolved_within_its_city(): void
    {
        $this->assertSame($this->at('North Luzon', 'Benguet', 'Atok', 'Poblacion'), $this->resolve('Benguet', 'Atok', 'Poblacion')['location']);
        $this->assertSame('Makati City', $this->resolve('Metro Manila', 'Makati', 'Poblacion')['location']['district']);
    }

    public function test_laoag_numbered_barangays_match_by_number(): void
    {
        $this->assertSame('Bgy. No. 1', $this->resolve('Ilocos Norte', 'Laoag City (Capital)', 'Bgy. No. 1, San Lorenzo (Pob.)')['location']['street']);
    }

    public function test_a_loose_match_two_barangays_share_is_refused(): void
    {
        // PSGC has "Balogo (Bacon District)" and "Balogo (Sorsogon East District)"; SPX only "Balogo".
        $this->assertSame(['ok' => false, 'missing' => 'barangay'], $this->resolve('Sorsogon', 'City Of Sorsogon (Capital)', 'Balogo (Bacon District)'));
    }

    public function test_unlisted_levels_are_named(): void
    {
        $this->assertSame(['ok' => false, 'missing' => 'province'], $this->resolve('Atlantis', 'Pandan', 'Bagumbayan'));
        $this->assertSame(['ok' => false, 'missing' => 'city'], $this->resolve('South Cotabato', "T'Boli", 'Aflek'));
        $this->assertSame(['ok' => false, 'missing' => 'barangay'], $this->resolve('Antique', 'Pandan', 'Nowhere'));
        $this->assertSame(['ok' => false, 'missing' => 'barangay'], $this->resolve('Antique', 'Pandan', ''));
    }

    public function test_compiles_once_and_rebuilds_when_the_list_changes(): void
    {
        $dir = sys_get_temp_dir().'/spx-loc-'.bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents("$dir/l.json", json_encode([['Visayas', 'Antique', 'Pandan', 'Old', '111100']]));
        $this->assertTrue((new SpxLocations("$dir/l.json", "$dir/c"))->resolve(['state' => 'Antique', 'city' => 'Pandan', 'barangay' => 'Old'])['ok']);
        $this->assertCount(1, glob("$dir/c/locations-*.php"));

        file_put_contents("$dir/l.json", json_encode([['Visayas', 'Antique', 'Pandan', 'New', '111100']]));
        touch("$dir/l.json", time() + 5);
        clearstatcache();
        $fresh = new SpxLocations("$dir/l.json", "$dir/c");
        $this->assertTrue($fresh->resolve(['state' => 'Antique', 'city' => 'Pandan', 'barangay' => 'New'])['ok']);
        $this->assertCount(1, glob("$dir/c/locations-*.php"));

        array_map('unlink', [...glob("$dir/c/*"), "$dir/l.json"]);
        rmdir("$dir/c");
        rmdir($dir);
    }
}
