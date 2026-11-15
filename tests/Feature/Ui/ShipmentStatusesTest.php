<?php

namespace Tests\Feature\Ui;

use App\Models\Admin;
use App\Models\AuditEntry;
use App\Models\CourierAccount;
use App\Models\ShipmentStatus;
use App\Models\StatusMapping;
use App\Shipments\PhaseResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Shipment statuses (name, enabled) and each courier's Status mapping tab. */
class ShipmentStatusesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(Admin::query()->create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'correct-horse-battery']));
    }

    private function shipmentStatus(string $key): ShipmentStatus
    {
        return ShipmentStatus::query()->where('key', $key)->firstOrFail();
    }

    private function resolve(string $courier, string $code): ?string
    {
        return app(PhaseResolver::class)->phase($courier, $code);
    }

    public function test_seeded_with_the_built_in_statuses_and_listed_in_order(): void
    {
        $this->get(route('statuses.index'))->assertOk()
            ->assertSeeInOrder(['Status name', 'Enabled', 'To hand over', 'In transit', 'Needs attention', 'Delivered', 'Failed', 'Lost or damaged', 'Returning', 'Cancelled'])
            ->assertSee(route('statuses.create'), false);
    }

    public function test_add_edit_and_validate(): void
    {
        $this->post(route('statuses.store'), ['name' => '  Held at hub ', 'enabled' => '1'])
            ->assertRedirect(route('statuses.index'))->assertSessionHas('status', 'Added');
        $held = $this->shipmentStatus('held_at_hub');
        $this->assertSame('Held at hub', $held->name);
        $this->assertTrue($held->enabled);

        $this->post(route('statuses.store'), ['name' => 'Held at hub'])->assertSessionHasErrors(['name' => 'A status with this name exists.']);
        $this->post(route('statuses.store'), ['name' => ' '])->assertSessionHasErrors(['name' => 'Enter a status name.']);
        $this->post(route('statuses.store'), ['name' => str_repeat('x', 41)])->assertSessionHasErrors('name');

        $this->flushSession();
        $this->get(route('statuses.edit', $held))->assertOk()->assertSee('value="Held at hub"', false)->assertSee('Delete');
        $this->put(route('statuses.update', $held), ['name' => 'At the hub', 'enabled' => '0'])->assertSessionHas('status', 'Saved');
        $held->refresh();
        $this->assertSame(['At the hub', false, 'held_at_hub'], [$held->name, $held->enabled, $held->key]);
        $this->assertSame(2, AuditEntry::query()->whereIn('action', ['shipment_status.created', 'shipment_status.updated'])->count());
    }

    public function test_switch_disables_and_the_courier_statuses_become_unmapped_then_return(): void
    {
        $delivered = $this->shipmentStatus('delivered');
        $this->assertSame('delivered', $this->resolve('spx', '4001'));

        $this->put(route('statuses.toggle', $delivered), ['enabled' => '0'])->assertSessionHas('status', 'Disabled');
        $this->assertNull($this->resolve('spx', '4001'));
        $this->assertNull($this->resolve('quadx', 'delivered'));

        $this->put(route('statuses.toggle', $delivered), ['enabled' => '1'])->assertSessionHas('status', 'Enabled');
        $this->assertSame('delivered', $this->resolve('spx', '4001'));
    }

    public function test_delete_unmaps_and_adding_the_built_in_back_restores_it(): void
    {
        $this->post(route('statuses.store'), ['name' => 'Held at hub', 'enabled' => '1']);
        StatusMapping::query()->create(['courier' => 'spx', 'courier_status' => '3001', 'phase' => 'held_at_hub']);

        $this->delete(route('statuses.destroy', $this->shipmentStatus('held_at_hub')))->assertSessionHas('status', 'Deleted');
        $this->assertNull($this->resolve('spx', '3001'));
        $this->post(route('statuses.store'), ['name' => 'Held at hub', 'enabled' => '1']);
        $this->assertNull($this->resolve('spx', '3001'), 'a new status never inherits a deleted one\'s mappings');

        $this->delete(route('statuses.destroy', $this->shipmentStatus('delivered')));
        $this->assertNull($this->resolve('spx', '4001'));
        $this->post(route('statuses.store'), ['name' => 'Delivered', 'enabled' => '1']);
        $this->assertSame('delivered', $this->resolve('spx', '4001'));
    }

    public function test_mapping_tab_for_spx_and_quadx_only(): void
    {
        $this->get(route('accounts.show', 'spx'))->assertOk()->assertSeeInOrder(['Settings', 'Status mapping'])->assertSee(route('accounts.mapping', 'spx'), false);
        $this->get(route('accounts.show', 'lbc'))->assertOk()->assertDontSee(route('accounts.mapping', 'lbc'), false);
        $this->get(route('accounts.mapping', 'lbc'))->assertNotFound();

        $this->get(route('accounts.mapping', 'spx'))->assertOk()
            ->assertSeeInOrder(['Code', 'SPX status', 'Shipment status', '1001', 'Pending Pickup', '4001', 'Delivered', '7001', 'Cancelled'])
            ->assertSee('<option value="" >Unmapped</option>', false);
        $this->get(route('accounts.mapping', 'quadx'))->assertOk()
            ->assertSeeInOrder(['QuadX status', 'Shipment status', 'Ready for pickup'])
            ->assertDontSee('<th scope="col">Code</th>', false);
    }

    public function test_dropdown_lists_enabled_statuses_and_shows_the_current_one(): void
    {
        $this->put(route('statuses.toggle', $this->shipmentStatus('lost_damaged')), ['enabled' => '0']);

        $html = $this->get(route('accounts.mapping', 'spx'))->assertOk()->getContent();
        $this->assertStringNotContainsString('>Lost or damaged</option>', $html);
        $this->assertMatchesRegularExpression('#name="statuses\[4001\]".*?<option value="delivered" selected>#s', $html);
        $this->assertMatchesRegularExpression('#name="statuses\[5003\]".*?<option value="" selected>Unmapped#s', $html);
    }

    public function test_save_mapping_stores_only_changes_and_returns_to_default(): void
    {
        CourierAccount::factory()->create(['courier' => 'spx']);
        $this->post(route('statuses.store'), ['name' => 'Held at hub', 'enabled' => '1']);

        $this->put(route('accounts.mapping.update', 'spx'), ['statuses' => ['3001' => 'held_at_hub', '4001' => 'delivered', '5001' => '', '9999' => 'delivered']])
            ->assertRedirect(route('accounts.mapping', 'spx'))->assertSessionHas('status', 'Saved');

        $this->assertSame('held_at_hub', $this->resolve('spx', '3001'));
        $this->assertNull($this->resolve('spx', '5001'));
        $this->assertSame(['3001' => 'held_at_hub', '5001' => 'unmapped'], StatusMapping::query()->where('courier', 'spx')->pluck('phase', 'courier_status')->all());
        $this->assertSame(1, AuditEntry::query()->where('action', 'status_mapping.updated')->count());

        // Back to the courier's own default: the row goes, so the default keeps applying.
        $this->put(route('accounts.mapping.update', 'spx'), ['statuses' => ['3001' => 'attention', '5001' => 'failed']]);
        $this->assertSame(0, StatusMapping::query()->where('courier', 'spx')->count());
    }

    public function test_menu_and_page_names(): void
    {
        $this->get(route('statuses.index'))->assertOk()->assertSee('>Couriers<', false)->assertSee('>Shipment statuses<', false);
        $this->get(route('accounts.index'))->assertOk()->assertSee('<title>Couriers', false);
    }

    public function test_courier_pages_live_under_couriers_and_old_links_redirect(): void
    {
        $this->assertSame(url('/couriers/spx/mapping'), route('accounts.mapping', 'spx'));
        $this->get('/accounts')->assertRedirect('/couriers')->assertStatus(301);
        $this->get('/accounts/spx')->assertRedirect('/couriers/spx');
        $this->get('/accounts/quadx/mapping')->assertRedirect('/couriers/quadx/mapping');
    }

    public function test_logos_on_the_courier_pages_and_cards(): void
    {
        $this->get(route('accounts.index'))->assertOk()
            ->assertSee('brand/couriers/spx.png', false)->assertSee('brand/couriers/quadx.png', false)->assertSee('brand/couriers/lbc.png', false);
        $this->get(route('accounts.show', 'spx'))->assertOk()->assertSee('brand/couriers/spx.png', false);
        $this->get(route('accounts.mapping', 'quadx'))->assertOk()->assertSee('brand/couriers/quadx.png', false);
    }
}
