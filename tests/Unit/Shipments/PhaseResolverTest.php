<?php

namespace Tests\Unit\Shipments;

use App\Models\StatusMapping;
use App\Shipments\PhaseResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PhaseResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_override_wins_then_courier_then_in_transit(): void
    {
        $resolver = new PhaseResolver;

        $this->assertSame('delivered', $resolver->phase('quadx', 'delivered'));
        $this->assertSame('to_handover', $resolver->phase('quadx', 'some_brand_new_status')); // the courier's own answer
        $this->assertSame('in_transit', $resolver->phase('nosuchcourier', 'whatever'));

        StatusMapping::create(['courier' => 'quadx', 'courier_status' => 'delivered', 'phase' => 'attention', 'label' => 'Check this']);
        StatusMapping::create(['courier' => 'quadx', 'courier_status' => 'some_brand_new_status', 'phase' => 'lost_damaged']);

        $this->assertSame('attention', $resolver->phase('quadx', 'delivered'));
        $this->assertSame('lost_damaged', $resolver->phase('quadx', 'some_brand_new_status'));
        $this->assertSame('Check this', $resolver->label('quadx', 'delivered'));
        $this->assertSame('Some brand new status', $resolver->label('quadx', 'some_brand_new_status'));
    }

    public function test_label_only_override_keeps_courier_phase(): void
    {
        StatusMapping::create(['courier' => 'quadx', 'courier_status' => 'delivered', 'phase' => null, 'label' => 'Received']);

        $this->assertSame('delivered', (new PhaseResolver)->phase('quadx', 'delivered'));
        $this->assertSame('Received', (new PhaseResolver)->label('quadx', 'delivered'));
    }

    public function test_override_outside_spine_rejected(): void
    {
        $this->expectException(ValidationException::class);

        StatusMapping::create(['courier' => 'quadx', 'courier_status' => 'delivered', 'phase' => 'foo']);
    }
}
