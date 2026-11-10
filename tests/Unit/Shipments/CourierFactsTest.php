<?php

namespace Tests\Unit\Shipments;

use App\Models\Shipment;
use App\Shipments\CourierFacts;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CourierFactsTest extends TestCase
{
    use RefreshDatabase;

    public function test_meta_merges_two_levels_and_nulls_never_erase(): void
    {
        $s = Shipment::factory()->create(['meta' => [
            'batch_no' => 1,
            'tracking_links' => ['delivery' => 'https://a.example/1', 'other' => 'https://a.example/2'],
            'proofs' => ['epod' => ['https://a.example/p1.jpg']],
            'driver' => ['name' => 'Juan', 'phone' => '0917'],
        ]]);
        $facts = new CourierFacts;

        $changed = $facts->apply($s, ['meta' => [
            'tracking_links' => ['delivery' => 'https://a.example/new'],
            'proofs' => ['epop' => ['https://a.example/pu.jpg']],
            'driver' => ['name' => null, 'phone' => '0918'],
            'edd' => null,
            'empty' => [],
        ]], now(), 'poll');

        $this->assertTrue($changed);
        $this->assertSame([
            'batch_no' => 1,
            'tracking_links' => ['delivery' => 'https://a.example/new', 'other' => 'https://a.example/2'],
            'proofs' => ['epod' => ['https://a.example/p1.jpg'], 'epop' => ['https://a.example/pu.jpg']],
            'driver' => ['name' => 'Juan', 'phone' => '0918'],
        ], $s->refresh()->meta);
        $this->assertFalse($facts->apply($s, ['meta' => ['batch_no' => 1]], now(), 'poll'), 'nothing new, nothing saved');
        $this->assertFalse($facts->apply($s, ['actual_fee' => null, 'meta' => []], now(), 'poll'));
    }

    public function test_actual_fee_follows_the_newest_report(): void
    {
        $s = Shipment::factory()->create(['actual_fee' => null, 'meta' => []]);
        $facts = new CourierFacts;
        $t = CarbonImmutable::parse('2026-11-10 10:00:00', 'Asia/Manila');

        $facts->apply($s, ['actual_fee' => 40], $t, 'webhook');
        $this->assertSame('40.00', $s->refresh()->actual_fee);

        $facts->apply($s, ['actual_fee' => 30], $t->subMinute(), 'webhook');
        $this->assertSame('40.00', $s->refresh()->actual_fee, 'older report ignored');

        $facts->apply($s, ['actual_fee' => 0], $t->addMinute(), 'poll');
        $this->assertSame('40.00', $s->refresh()->actual_fee, 'a zero never replaces a positive fee');

        $facts->apply($s, ['actual_fee' => '45.5'], $t->addMinutes(2), 'poll');
        $s->refresh();
        $this->assertSame('45.50', $s->actual_fee);
        $this->assertSame('poll', $s->meta['actual_fee_source']);

        $zero = Shipment::factory()->create(['actual_fee' => null, 'meta' => []]);
        $facts->apply($zero, ['actual_fee' => 0], $t, 'webhook');
        $this->assertSame('0.00', $zero->refresh()->actual_fee, 'a first zero is still a value');
    }
}
