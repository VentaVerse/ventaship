<?php

namespace Tests\Unit;

use App\Support\PhAddress;
use Tests\TestCase;

class PhAddressTest extends TestCase
{
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
}
