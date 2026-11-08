<?php

namespace Tests\Unit\Couriers;

use App\Couriers\CourierRegistry;
use App\Couriers\QuadX\QuadXFulfillment;
use App\Couriers\Support\PhaseTone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Task W1 (fulfillment-workflow-v2 plan): QuadXFulfillment::statusPhase()
 * re-bucketed onto the common 8-phase spine. QuadX's raw status vocabulary
 * itself (terminalStatuses/cancelableStatuses/mappableStatuses) is FROZEN —
 * this only proves every raw status QuadX ever produces lands in exactly one
 * spine bucket, and that the legacy 4-step happy-path stepper (still
 * consumed by the queue view's 'step'/'failed' keys) keeps resolving
 * correctly for the book -> handover -> transit -> delivered path.
 *
 * Extends the Laravel TestCase so the courier registry is booted; the
 * courier comes from CourierRegistry::describe(), a schema-only instance.
 * statusPhase() itself touches neither the database nor any facade.
 */
class QuadXStatusPhaseTest extends TestCase
{
    use RefreshDatabase;

    private QuadXFulfillment $courier;

    protected function setUp(): void
    {
        parent::setUp();
        $courier = CourierRegistry::describe('quadx');
        $this->assertInstanceOf(QuadXFulfillment::class, $courier);
        $this->courier = $courier;
    }

    /**
     * Every raw status referenced anywhere in QuadXFulfillment (its
     * terminalStatuses(), cancelableStatuses(), mappableStatuses(), and the
     * fuller vocabulary its pre-spine statusPhase() already recognized) plus
     * a null/unset status, mapped to the ONE spine bucket it belongs in.
     */
    public static function rawStatusBucketProvider(): array
    {
        return [
            // Booked, awaiting courier collection/drop-off.
            'unset status (fresh booking)' => [null, 'to_handover'],
            'empty string status'          => ['', 'to_handover'],
            'pending'                      => ['pending', 'to_handover'],
            'confirmed'                    => ['confirmed', 'to_handover'],
            'for_pickup'                   => ['for_pickup', 'to_handover'],
            'searching_for_rider'          => ['searching_for_rider', 'to_handover'],
            'for_acceptance'               => ['for_acceptance', 'to_handover'],
            'for_consolidation'            => ['for_consolidation', 'to_handover'],
            'for_dropoff'                  => ['for_dropoff', 'to_handover'],
            'for_payment'                  => ['for_payment', 'to_handover'],
            'paid'                         => ['paid', 'to_handover'],

            // Courier has it.
            'picked_up'                    => ['picked_up', 'in_transit'],
            'in_transit'                   => ['in_transit', 'in_transit'],
            'out_for_delivery'             => ['out_for_delivery', 'in_transit'],
            'for_delivery'                 => ['for_delivery', 'in_transit'],
            'in_transit_to_delivery_area'  => ['in_transit_to_delivery_area', 'in_transit'],
            'received_at_delivery_hub'     => ['received_at_delivery_hub', 'in_transit'],
            'received_at_delivery_area'    => ['received_at_delivery_area', 'in_transit'],
            'at_sorting_center'            => ['at_sorting_center', 'in_transit'],
            'at_3pl_facility'              => ['at_3pl_facility', 'in_transit'],
            'received_at_pickup_hub'       => ['received_at_pickup_hub', 'in_transit'],
            'claimed'                      => ['claimed', 'in_transit'],
            'confirmed_transit'            => ['confirmed_transit', 'in_transit'],

            // At the buyer.
            'delivered' => ['delivered', 'delivered'],

            // Ended badly (delivery failed / lost / damaged).
            'failed_pickup'         => ['failed_pickup', 'failed'],
            'failed_pickup_attempt' => ['failed_pickup_attempt', 'failed'],
            'failed_delivery'       => ['failed_delivery', 'failed'],
            'for_reattempt'         => ['for_reattempt', 'failed'],
            'rejected'              => ['rejected', 'failed'],
            'lost'                  => ['lost', 'failed'],
            'damaged'               => ['damaged', 'failed'],
            'disposed'              => ['disposed', 'failed'],
            'failed_return'         => ['failed_return', 'failed'],

            // Booking cancelled.
            'canceled (single L)'  => ['canceled', 'cancelled'],
            'cancelled (double L)' => ['cancelled', 'cancelled'],

            // Coming back to the seller.
            'returned'           => ['returned', 'returning'],
            'return_in_transit'  => ['return_in_transit', 'returning'],
            'out_for_return'     => ['out_for_return', 'returning'],
            'for_return'         => ['for_return', 'returning'],
        ];
    }

    #[DataProvider('rawStatusBucketProvider')]
    public function test_every_raw_status_lands_in_exactly_one_spine_bucket(?string $rawStatus, string $expectedPhase): void
    {
        $result = $this->courier->statusPhase($rawStatus);

        $this->assertArrayHasKey('phase', $result);
        $this->assertSame($expectedPhase, $result['phase']);
        $this->assertContains($result['phase'], PhaseTone::PHASES, 'phase must be one of the 8 spine buckets');

        // QuadX never produces 'to_pack' or 'booking' from a raw status —
        // 'to_pack' is "no booking exists yet" (a FulfillmentService-level
        // concern, not a courier status), and 'booking' is for async
        // couriers whose createShipment() doesn't return a status
        // synchronously (QuadX's does).
        $this->assertNotSame('to_pack', $result['phase']);
        $this->assertNotSame('booking', $result['phase']);
    }

    // ------------------------------------------------------------------
    // Backward compatibility: the 4-step happy-path stepper must still
    // derive from statusPhase()'s 'step'/'failed' keys exactly as before —
    // failed/cancelled/returning are terminal badge states, not steps.
    // ------------------------------------------------------------------

    public function test_happy_path_still_derives_the_legacy_four_step_stepper(): void
    {
        $this->assertSame(['step' => 1, 'failed' => false], $this->stepAndFailed('for_pickup'));
        $this->assertSame(['step' => 2, 'failed' => false], $this->stepAndFailed('picked_up'));
        $this->assertSame(['step' => 2, 'failed' => false], $this->stepAndFailed('in_transit'));
        $this->assertSame(['step' => 3, 'failed' => false], $this->stepAndFailed('delivered'));
    }

    public function test_terminal_badge_states_still_flag_failed_on_the_legacy_stepper(): void
    {
        $this->assertTrue($this->courier->statusPhase('canceled')['failed']);
        $this->assertTrue($this->courier->statusPhase('cancelled')['failed']);
        $this->assertTrue($this->courier->statusPhase('returned')['failed']);
        $this->assertTrue($this->courier->statusPhase('failed_delivery')['failed']);
    }

    private function stepAndFailed(?string $status): array
    {
        $result = $this->courier->statusPhase($status);
        return ['step' => $result['step'], 'failed' => $result['failed']];
    }
}
