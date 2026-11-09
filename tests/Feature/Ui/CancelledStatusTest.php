<?php

namespace Tests\Feature\Ui;

use App\Couriers\CourierRegistry;
use App\Couriers\FakeCourier;
use App\Fulfillment\ShipmentList;
use App\Fulfillment\StatusFilter;
use App\Models\Admin;
use App\Models\Shipment;
use App\Models\StatusMapping;
use App\Shipments\Canceller;
use App\Shipments\ShipmentCreator;
use App\Shipments\TrackingRefresher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsShipments;
use Tests\TestCase;

/** A shipment cancelled from Ventaship reads "Cancelled", not the courier's last status. */
class CancelledStatusTest extends TestCase
{
    use BuildsShipments, RefreshDatabase;

    private FakeCourier $fake;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeDefaultAccount();
        $this->fake = FakeCourier::make('quadx')->booksAs('QX-1')->cancels();
        CourierRegistry::fake('quadx', $this->fake);
        $this->actingAs(Admin::query()->create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'correct-horse-battery']));
    }

    private function book(string $reference): Shipment
    {
        $s = app(ShipmentCreator::class)->create(null, $this->shipmentBody(['client_reference' => $reference]), 'manual', now: true);
        $this->assertSame('booked', $s->state);
        $this->assertSame('pending', $s->courier_status);

        return $s;
    }

    /** @return list<string> */
    private function listed(string $status): array
    {
        return ShipmentList::query(['status' => $status])->pluck('public_id')->all();
    }

    public function test_cancelled_in_ventaship_reads_cancelled_everywhere(): void
    {
        $cancelled = $this->book('C-1');
        $live = $this->book('L-1');

        $cancelled = app(Canceller::class)->request($cancelled, now: true);
        $this->assertSame('cancelled', $cancelled->state);
        $this->assertSame('pending', $cancelled->courier_status);

        $this->assertSame('ventaship:cancelled', StatusFilter::keyFor($cancelled));
        $this->assertSame('Cancelled', StatusFilter::labelFor($cancelled));

        $this->assertSame([$live->public_id], $this->listed('quadx:pending'));
        $this->assertSame([$cancelled->public_id], $this->listed('ventaship:cancelled'));

        $options = collect(StatusFilter::options([]))->pluck('count', 'key')->all();
        $this->assertSame(['quadx:pending' => 1, 'ventaship:cancelled' => 1], $options);

        $this->get('/?status=quadx:pending')->assertOk()->assertSeeText($live->client_reference)->assertDontSeeText($cancelled->client_reference);
        $this->get('/?status=ventaship:cancelled')->assertOk()->assertSeeText($cancelled->client_reference)->assertDontSeeText($live->client_reference);

        $this->getJson('/fulfillment/poll?ids='.$cancelled->public_id)->assertJsonPath('statuses.'.$cancelled->public_id, 'ventaship:cancelled');

        $html = $this->get(route('shipments.show', $cancelled->public_id))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#sd-title-badge"><span[^>]*>\s*<span class="x-badge__dot"[^>]*></span>\s*<span class="x-badge__label">Cancelled</span>#', $html);
    }

    public function test_courier_reported_cancel_keeps_the_courier_key(): void
    {
        StatusMapping::query()->create(['courier' => 'quadx', 'courier_status' => 'void', 'phase' => 'cancelled', 'label' => 'Voided']);
        $s = $this->book('V-1');
        $this->fake->tracks([['status' => 'void']]);
        $s = app(TrackingRefresher::class)->refresh($s);
        $this->assertSame('cancelled', $s->refresh()->state);
        $ours = app(Canceller::class)->request($this->book('C-1'), now: true);

        $this->assertSame('quadx:void', StatusFilter::keyFor($s));
        $this->assertSame('Voided', StatusFilter::labelFor($s));
        $this->assertSame([$s->public_id], $this->listed('quadx:void'));
        $this->assertSame([$ours->public_id], $this->listed('ventaship:cancelled'));
        $this->assertSame([], $this->listed('quadx:pending'));
    }
}
