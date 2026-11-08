<?php

namespace Tests\Feature;

use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BaselineTest extends TestCase
{
    public function test_stray_http_is_blocked(): void
    {
        $this->expectException(StrayRequestException::class);

        Http::get('https://example.com');
    }

    public function test_timezone_is_manila(): void
    {
        $this->assertSame('Asia/Manila', config('app.timezone'));
    }

    public function test_live_flags_default_off(): void
    {
        $this->assertFalse(config('couriers.lbc_live'));
        $this->assertFalse(config('couriers.spx_live'));
    }

    public function test_vault_and_signature_config(): void
    {
        $this->assertNotEmpty(config('ventaship.vault_key'));
        $this->assertSame(72, config('ventaship.outbox_give_up_hours'));
    }

    public function test_couriers_queue_connection(): void
    {
        $this->assertSame('database', config('queue.connections.couriers.driver'));
        $this->assertSame(900, config('queue.connections.couriers.retry_after'));
    }
}
