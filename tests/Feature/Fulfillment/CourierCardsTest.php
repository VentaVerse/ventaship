<?php

namespace Tests\Feature\Fulfillment;

use App\Fulfillment\CourierCards;
use App\Models\CourierAccount;
use App\Models\Shipment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CourierCardsTest extends TestCase
{
    use RefreshDatabase;

    private function ship(CourierAccount $a, string $phase, array $over = []): Shipment
    {
        return Shipment::factory()->create(array_merge([
            'courier_account_id' => $a->id,
            'courier' => $a->courier,
            'phase' => $phase,
            'payment_state' => 'prepaid',
        ], $over));
    }

    public function test_cards_include_muted_entries_for_missing_couriers(): void
    {
        $spxB = CourierAccount::factory()->create(['courier' => 'spx', 'label' => 'B shop']);
        $spxA = CourierAccount::factory()->create(['courier' => 'spx', 'label' => 'A shop']);
        CourierAccount::factory()->create(['courier' => 'lbc', 'status' => 'disabled']);

        $cards = CourierCards::all();

        $this->assertSame(['quadx', 'spx', 'spx', 'lbc'], array_column($cards, 'courier'));
        $this->assertSame([$spxA->id, $spxB->id], [$cards[1]['account']->id, $cards[2]['account']->id]);
        $this->assertNull($cards[0]['account']);
        $this->assertNull($cards[3]['account']);
        $this->assertSame(0, $cards[0]['active']);
        $this->assertSame('0.00', $cards[0]['cod_unpaid']);
    }

    public function test_cards_show_active_delivered_cod_unpaid(): void
    {
        $a = CourierAccount::factory()->create(['courier' => 'quadx']);
        $b = CourierAccount::factory()->create(['courier' => 'quadx']);
        foreach (['draft', 'attention', 'booking', 'to_handover', 'in_transit', 'in_transit', 'failed'] as $p) {
            $this->ship($a, $p);
        }
        $this->ship($a, 'delivered');
        $this->ship($a, 'cancelled');
        $this->ship($a, 'returning', ['terminal_at' => now()]);
        $this->ship($b, 'delivered');
        $this->ship($a, 'in_transit', ['payment_state' => 'cod_unpaid', 'cod_amount' => 10]);

        $card = collect(CourierCards::all())->first(fn ($c) => $c['account']?->id === $a->id);

        $this->assertSame(8, $card['active']);
        $this->assertSame(1, $card['delivered']);
        $this->assertSame('10.00', $card['cod_unpaid']);
        $this->assertArrayNotHasKey('to_handover', $card);
        $this->assertArrayNotHasKey('shipping', $card);
        $this->assertArrayNotHasKey('failed', $card);
    }

    public function test_cod_unpaid_excludes_cancelled_and_uses_cod_amount(): void
    {
        $a = CourierAccount::factory()->create(['courier' => 'quadx']);
        $this->ship($a, 'in_transit', ['payment_state' => 'cod_unpaid', 'cod_amount' => 1000.50, 'total' => 9999]);
        $this->ship($a, 'draft', ['payment_state' => 'cod_unpaid', 'cod_amount' => 239.50]);
        $this->ship($a, 'cancelled', ['payment_state' => 'cod_unpaid', 'cod_amount' => 700]);
        $this->ship($a, 'delivered', ['payment_state' => 'cod_collected', 'cod_amount' => 300]);

        $card = collect(CourierCards::all())->first(fn ($c) => $c['account']?->id === $a->id);

        $this->assertSame('1240.00', $card['cod_unpaid']);
    }

    public function test_cod_total_is_exact_where_floats_drift(): void
    {
        $a = CourierAccount::factory()->create(['courier' => 'quadx']);
        foreach (['0.10', '0.20', '12345678.91'] as $amt) {
            $this->ship($a, 'in_transit', ['payment_state' => 'cod_unpaid', 'cod_amount' => $amt]);
        }

        $card = collect(CourierCards::all())->first(fn ($c) => $c['account']?->id === $a->id);

        $this->assertSame('12345679.21', $card['cod_unpaid']);
    }
}
