<?php

namespace Tests\Unit;

use App\Support\PhAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class PhAddressTest extends TestCase
{
    use RefreshDatabase;

    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/ph-test-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($this->tmp.'/data');
        File::ensureDirectoryExists($this->tmp.'/compiled');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    public function test_tables_load_on_the_database_cache_store_without_writing_cache_rows(): void
    {
        config(['cache.default' => 'database']);
        $ph = new PhAddress(database_path('data/ph'), $this->tmp.'/compiled');

        $this->assertCount(82, $ph->provinces());
        $this->assertNotEmpty($ph->barangays('030801000'));
        $this->assertSame(0, DB::table('cache')->count());
    }

    public function test_a_table_is_compiled_once_and_reused(): void
    {
        copy(database_path('data/ph/provinces.json'), $this->tmp.'/data/provinces.json');
        touch($this->tmp.'/data/provinces.json', 1_700_000_000);
        $ph = new PhAddress($this->tmp.'/data', $this->tmp.'/compiled');

        $this->assertCount(82, $ph->provinces());
        $compiled = $this->tmp.'/compiled/provinces-1700000000.php';
        $this->assertFileExists($compiled);

        // Reused: past the per-request memo, the compiled file is read instead of the source.
        (new \ReflectionProperty(PhAddress::class, 'memo'))->setValue(null, []);
        file_put_contents($compiled, '<?php return '.var_export(['byCode' => [], 'byParent' => ['' => [['code' => '1', 'name' => 'Only']]]], true).';');
        $this->assertSame([['code' => '1', 'name' => 'Only']], (new PhAddress($this->tmp.'/data', $this->tmp.'/compiled'))->provinces());
    }

    public function test_a_changed_source_rebuilds_and_drops_the_old_compiled_file(): void
    {
        $source = $this->tmp.'/data/provinces.json';
        file_put_contents($source, json_encode([['code' => '1', 'name' => 'Old']]));
        touch($source, 1_700_000_000);
        $this->assertSame('Old', (new PhAddress($this->tmp.'/data', $this->tmp.'/compiled'))->provinces()[0]['name']);

        file_put_contents($source, json_encode([['code' => '1', 'name' => 'New']]));
        touch($source, 1_700_000_100);
        $this->assertSame('New', (new PhAddress($this->tmp.'/data', $this->tmp.'/compiled'))->provinces()[0]['name']);

        $this->assertFileDoesNotExist($this->tmp.'/compiled/provinces-1700000000.php');
        $this->assertSame(['provinces-1700000100.php'], array_map('basename', glob($this->tmp.'/compiled/*')));
    }

    public function test_non_string_codes_or_names_do_not_match(): void
    {
        $ph = app(PhAddress::class);
        $this->assertFalse($ph->matchesPosted(['state' => 'Metro Manila', 'codes' => ['state' => ['x']]]));
        $this->assertFalse($ph->matchesPosted(['state' => ['Metro Manila'], 'codes' => ['state' => '130000000']]));
        $this->assertFalse($ph->matchesPosted(['state' => 'Metro Manila', 'codes' => 'x']));
    }

    public function test_files_load_and_counts_match(): void
    {
        $ph = app(PhAddress::class);
        $this->assertCount(82, $ph->provinces());
        $this->assertSame(1632, array_sum(array_map(fn ($p) => count($ph->cities($p['code'])), $ph->provinces())));
        $this->assertCount(14, $ph->districts('133900000'));

        $total = 0;
        $parents = array_merge(
            array_map(fn ($d) => $d['code'], $ph->districts('133900000')),
            ...array_map(fn ($p) => array_map(fn ($c) => $c['code'], $ph->cities($p['code'])), $ph->provinces()),
        );
        foreach ($parents as $parent) {
            $total += count($ph->barangays($parent));
        }
        $this->assertSame(41954, $total);

        $names = array_column($ph->provinces(), 'name');
        $sorted = $names;
        natcasesort($sorted);
        $this->assertSame(array_values($sorted), $names);
    }

    public function test_manila_has_districts_and_barangays_hang_off_them(): void
    {
        $ph = app(PhAddress::class);
        $this->assertSame('Metro Manila', $ph->find('provinces', '130000000')['name']);
        $manila = $ph->find('cities', '133900000');
        $this->assertSame('Manila', $manila['name']);
        $this->assertSame('1006', $manila['zip']);

        $districts = $ph->districts('133900000');
        $this->assertNotEmpty($districts);
        $this->assertSame([], $ph->barangays('133900000'));
        $this->assertNotEmpty($ph->barangays($districts[0]['code']));

        $this->assertSame([], $ph->districts('030801000'));
        $this->assertNotEmpty($ph->barangays('030801000'));
        $this->assertSame([], $ph->barangays('nope'));
    }

    public function test_valid_chain_rejects_mismatched_parent(): void
    {
        $ph = app(PhAddress::class);
        $district = $ph->districts('133900000')[0];
        $brgy = $ph->barangays($district['code'])[0];

        $this->assertTrue($ph->validChain('130000000', '133900000', $district['code'], $brgy['code']));
        $this->assertTrue($ph->validChain('130000000', '133900000', null, null));

        // Manila barangay must go through its district.
        $this->assertFalse($ph->validChain('130000000', '133900000', null, $brgy['code']));
        // Wrong province for the city.
        $this->assertFalse($ph->validChain('175300000', '133900000', null, null));
        // Barangay of another city.
        $other = $ph->barangays('030801000')[0];
        $this->assertFalse($ph->validChain('130000000', '133900000', $district['code'], $other['code']));
        $this->assertTrue($ph->validChain($ph->find('cities', '030801000')['parent'], '030801000', null, $other['code']));
        $this->assertFalse($ph->validChain('130000000', 'bogus', null, null));
        $this->assertFalse($ph->validChain(null, '133900000', null, null));
    }

    public function test_posted_address_must_match_its_codes(): void
    {
        $ph = app(PhAddress::class);
        $district = $ph->districts('133900000')[0];
        $brgy = $ph->barangays($district['code'])[0];
        $ok = [
            'state' => 'Metro Manila', 'city' => 'Manila', 'district' => $district['name'], 'barangay' => $brgy['name'],
            'codes' => ['state' => '130000000', 'city' => '133900000', 'barangay' => $brgy['code']],
        ];
        $this->assertTrue($ph->matchesPosted($ok));
        $this->assertFalse($ph->matchesPosted(['city' => 'Quezon City'] + $ok));
        $this->assertFalse($ph->matchesPosted(['district' => ''] + $ok));
        $this->assertFalse($ph->matchesPosted(['codes' => ['barangay' => '000'] + $ok['codes']] + $ok));
    }

    public function test_codes_are_optional_per_level_but_checked_where_present(): void
    {
        $ph = app(PhAddress::class);
        // Legacy: coded province, free-text city.
        $this->assertTrue($ph->matchesPosted(['state' => 'Metro Manila', 'city' => 'Atlantis', 'codes' => ['state' => '130000000']]));
        // Coded level with a wrong name fails.
        $this->assertFalse($ph->matchesPosted(['state' => 'Cebu', 'city' => 'Atlantis', 'codes' => ['state' => '130000000']]));
        // Coded city under the wrong coded province fails.
        $this->assertFalse($ph->matchesPosted(['state' => 'Palawan', 'city' => 'Manila', 'codes' => ['state' => '175300000', 'city' => '133900000']]));
        // Unknown code fails; no codes at all is free text.
        $this->assertFalse($ph->matchesPosted(['state' => 'x', 'codes' => ['state' => 'bogus']]));
        $this->assertTrue($ph->matchesPosted(['state' => 'Anywhere', 'city' => 'Else']));
    }

    public function test_name_matching_collapses_whitespace(): void
    {
        $this->assertSame('133900000', app(PhAddress::class)->matchName('cities', '130000000', '  mANILA ')['code']);
        $this->assertSame('137404000', app(PhAddress::class)->matchName('cities', '130000000', "quezon   \t city")['code']);
    }
}
