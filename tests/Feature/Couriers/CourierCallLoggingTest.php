<?php

namespace Tests\Feature\Couriers;

use App\Couriers\CourierRegistry;
use App\Models\CourierCall;
use App\Models\Shipment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsCourierAccounts;
use Tests\TestCase;

class CourierCallLoggingTest extends TestCase
{
    use BuildsCourierAccounts, RefreshDatabase;

    public function test_call_logged_without_auth_header(): void
    {
        Http::fake(['api.staging.quadx.xyz/*' => Http::response($this->quadxFixture('print-awb'))]);
        $account = $this->quadxAccount('qx-key-SECRET1', 'qx-secret-SECRET2');
        $shipment = Shipment::factory()->create(['courier_account_id' => $account->id]);

        Context::add(['account_id' => $account->id, 'shipment_id' => $shipment->id, 'operation' => 'label']);
        CourierRegistry::for($account)->printLabel('QX-TEST-0001', 'zebra');

        $call = CourierCall::query()->sole();
        $this->assertSame($account->id, (int) $call->courier_account_id);
        $this->assertSame($shipment->id, (int) $call->shipment_id);
        $this->assertSame('quadx', $call->courier);
        $this->assertSame('label', $call->operation);
        $this->assertSame('GET', $call->method);
        $this->assertSame('api.staging.quadx.xyz/v2/print/awb/QX-TEST-0001', $call->host_path);
        $this->assertSame(200, (int) $call->status);
        $this->assertNull($call->excerpt, 'no excerpt for a success');
        $this->assertNull($call->error);

        $row = json_encode(DB::table('courier_calls')->first());
        $this->assertStringNotContainsString('Bearer', $row);
        $this->assertStringNotContainsString('eyJ', $row, 'no JWT');
        $this->assertStringNotContainsString('SECRET', $row);
        $this->assertStringNotContainsString('type=zebra', $row, 'no query string');
    }

    public function test_failure_keeps_masked_excerpt(): void
    {
        Http::fake(['api.staging.quadx.xyz/*' => Http::response([
            'errors' => ['detail' => 'Invalid mobile number 09171234567 for buyer.'],
            'buyer_name' => 'Ñora Dela Cruz',
            'delivery_address' => ['name' => 'Ñora Dela Cruz', 'mobile_number' => '+63 917 123 4567'],
            'padding' => str_repeat('x', 8000),
        ], 422)]);
        $account = $this->quadxAccount();

        Context::add(['account_id' => $account->id, 'operation' => 'create']);
        CourierRegistry::for($account)->cancelShipment('QX-TEST-0001');

        $call = CourierCall::query()->sole();
        $this->assertSame(422, (int) $call->status);
        $this->assertSame('HTTP 422', $call->error);
        $this->assertNotNull($call->excerpt);
        $this->assertLessThanOrEqual(4096, strlen($call->excerpt));
        $this->assertStringNotContainsString('Dela Cruz', $call->excerpt);
        $this->assertStringNotContainsString('09171234567', $call->excerpt);
        $this->assertStringNotContainsString('917 123 4567', $call->excerpt);
        $this->assertStringContainsString('Invalid mobile number', $call->excerpt);
    }

    public function test_connection_failure_is_logged(): void
    {
        Http::fake(['api.staging.quadx.xyz/*' => Http::failedConnection('cURL error 28: Operation timed out for https://api.staging.quadx.xyz/v2/orders/QX-TEST-0001?token=abc')]);
        $account = $this->quadxAccount();

        Context::add(['account_id' => $account->id, 'operation' => 'track']);
        $result = CourierRegistry::for($account)->getTracking('QX-TEST-0001');

        $this->assertFalse($result['success']);
        $call = CourierCall::query()->sole();
        $this->assertNull($call->status);
        $this->assertStringContainsString('timed out', $call->error);
        $this->assertStringNotContainsString('token=abc', $call->error);
    }

    public function test_calls_outside_a_courier_context_are_not_logged(): void
    {
        Http::fake(['example.test/*' => Http::response('ok')]);

        Http::get('https://example.test/hook');

        $this->assertSame(0, CourierCall::query()->count());
    }
}
