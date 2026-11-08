<?php

namespace Tests\Feature;

use App\Models\ClientApp;
use App\Models\CourierAccount;
use App\Models\Shipment;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_ids_are_prefixed(): void
    {
        $app = ClientApp::factory()->create();
        $account = CourierAccount::factory()->create();
        $shipment = Shipment::factory()->create();

        $this->assertStringStartsWith('app_', $app->public_id);
        $this->assertStringStartsWith('acc_', $account->public_id);
        $this->assertStringStartsWith('shp_', $shipment->public_id);
        foreach ([$app, $account, $shipment] as $m) {
            $this->assertSame(30, strlen($m->public_id));
            $this->assertSame('public_id', $m->getRouteKeyName());
        }
    }

    public function test_one_live_reference_per_app(): void
    {
        $app = ClientApp::factory()->create();
        Shipment::factory()->create(['client_app_id' => $app->id, 'active_reference' => '4211']);

        $this->expectException(UniqueConstraintViolationException::class);
        Shipment::factory()->create(['client_app_id' => $app->id, 'active_reference' => '4211']);
    }

    public function test_null_active_references_do_not_collide(): void
    {
        $app = ClientApp::factory()->create();
        Shipment::factory()->count(2)->create(['client_app_id' => $app->id, 'active_reference' => null]);

        $this->assertSame(2, Shipment::count());
    }

    public function test_one_default_account_per_courier(): void
    {
        CourierAccount::factory()->create(['courier' => 'quadx', 'is_default' => true, 'default_slot' => 'quadx']);

        $this->expectException(UniqueConstraintViolationException::class);
        CourierAccount::factory()->create(['courier' => 'quadx', 'is_default' => true, 'default_slot' => 'quadx']);
    }

    public function test_recipient_is_encrypted_at_rest(): void
    {
        $shipment = Shipment::factory()->create(['recipient' => ['first_name' => 'Ñora', 'phone' => '09171234567']]);

        $raw = DB::table('shipments')->value('recipient');
        $this->assertStringNotContainsString('Ñora', $raw);
        $this->assertStringNotContainsString('09171234567', $raw);
        $this->assertSame('Ñora', $shipment->fresh()->recipient['first_name']);
    }

    public function test_secrets_are_encrypted_at_rest(): void
    {
        $app = ClientApp::factory()->create(['webhook_secret' => 'whsec_plain']);

        $this->assertStringNotContainsString('whsec_plain', DB::table('client_apps')->value('webhook_secret'));
        $this->assertSame('whsec_plain', $app->fresh()->webhook_secret);
    }
}
