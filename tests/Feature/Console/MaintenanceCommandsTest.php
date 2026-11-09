<?php

namespace Tests\Feature\Console;

use App\Models\ClientApp;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MaintenanceCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_logs_prune_keeps_each_table_for_its_window(): void
    {
        $app = ClientApp::factory()->create();
        $now = now();

        DB::table('api_requests')->insert([
            ['method' => 'GET', 'path' => 'old', 'status' => 200, 'created_at' => $now->copy()->subDays(31)],
            ['method' => 'GET', 'path' => 'new', 'status' => 200, 'created_at' => $now->copy()->subDays(29)],
        ]);
        DB::table('courier_calls')->insert([
            ['courier' => 'quadx', 'operation' => 'old', 'method' => 'GET', 'host_path' => 'x', 'created_at' => $now->copy()->subDays(91)],
            ['courier' => 'quadx', 'operation' => 'new', 'method' => 'GET', 'host_path' => 'x', 'created_at' => $now->copy()->subDays(89)],
        ]);
        DB::table('webhook_receipts')->insert([
            ['courier' => 'quadx', 'status' => 'processed', 'body' => 'old', 'received_at' => $now->copy()->subDays(91)],
            ['courier' => 'quadx', 'status' => 'processed', 'body' => 'new', 'received_at' => $now->copy()->subDays(89)],
            ['courier' => 'quadx', 'status' => 'unrouted', 'body' => 'old-unrouted', 'received_at' => $now->copy()->subDays(8)],
            ['courier' => 'quadx', 'status' => 'unrouted', 'body' => 'new-unrouted', 'received_at' => $now->copy()->subDays(6)],
        ]);
        $eventId = DB::table('outgoing_events')->insertGetId([
            'public_id' => 'evt_01TESTTESTTESTTESTTESTTEST', 'client_app_id' => $app->id, 'type' => 'shipment.booked',
            'payload' => '{}', 'status' => 'delivered', 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('outgoing_attempts')->insert([
            ['outgoing_event_id' => $eventId, 'status_code' => 500, 'created_at' => $now->copy()->subDays(91)],
            ['outgoing_event_id' => $eventId, 'status_code' => 200, 'created_at' => $now->copy()->subDays(89)],
        ]);
        DB::table('idempotency_keys')->insert([
            ['client_app_id' => $app->id, 'key' => 'done-old', 'request_hash' => str_repeat('a', 64), 'status' => 'completed', 'created_at' => $now->copy()->subDays(8)],
            ['client_app_id' => $app->id, 'key' => 'done-new', 'request_hash' => str_repeat('a', 64), 'status' => 'completed', 'created_at' => $now->copy()->subDays(6)],
            ['client_app_id' => $app->id, 'key' => 'stuck', 'request_hash' => str_repeat('a', 64), 'status' => 'processing', 'created_at' => $now->copy()->subHours(2)],
            ['client_app_id' => $app->id, 'key' => 'stuck-older', 'request_hash' => str_repeat('a', 64), 'status' => 'processing', 'created_at' => $now->copy()->subDays(9)],
        ]);
        $this->artisan('ventaship:logs-prune')
            ->expectsOutputToContain('2 idempotency keys still processing after an hour')
            ->assertExitCode(0);

        $this->assertSame(['new'], DB::table('api_requests')->pluck('path')->all());
        $this->assertSame(['new'], DB::table('courier_calls')->pluck('operation')->all());
        $this->assertEqualsCanonicalizing(['new', 'new-unrouted'], DB::table('webhook_receipts')->pluck('body')->all());
        $this->assertSame([200], DB::table('outgoing_attempts')->pluck('status_code')->map(fn ($v) => (int) $v)->all());
        $this->assertEqualsCanonicalizing(['done-new', 'stuck', 'stuck-older'], DB::table('idempotency_keys')->pluck('key')->all());
    }
}
