<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Tests\TestCase;

/** TRUSTED_PROXIES (config/trustedproxy.php) decides whether X-Forwarded-For gives the request IP. */
class TrustedProxiesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Route::get('/_test/ip', fn (Request $r) => response($r->ip()));
    }

    protected function tearDown(): void
    {
        // Trusted proxies are static on Symfony's Request: never let them leak into later tests.
        SymfonyRequest::setTrustedProxies([], SymfonyRequest::HEADER_X_FORWARDED_FOR);
        parent::tearDown();
    }

    public function test_no_proxy_is_trusted_by_default(): void
    {
        config(['trustedproxy.proxies' => null]); // what an empty TRUSTED_PROXIES gives

        $this->get('/_test/ip', ['X-Forwarded-For' => '203.0.113.9'])->assertSee('127.0.0.1');
    }

    public function test_a_configured_proxy_gives_the_forwarded_client_ip(): void
    {
        config(['trustedproxy.proxies' => '10.0.0.0/8, 127.0.0.1']);

        $this->get('/_test/ip', ['X-Forwarded-For' => '203.0.113.9'])->assertSee('203.0.113.9');
    }

    public function test_a_forwarded_header_from_an_untrusted_caller_is_ignored(): void
    {
        config(['trustedproxy.proxies' => '10.0.0.1']);

        $this->get('/_test/ip', ['X-Forwarded-For' => '203.0.113.9'])->assertSee('127.0.0.1');
    }

    public function test_spx_unverified_budget_is_keyed_by_the_real_client_ip(): void
    {
        config(['trustedproxy.proxies' => '127.0.0.1']);

        $this->call('POST', '/webhooks/spx', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9'], '{"id":"x"}')->assertOk();

        $this->assertSame(1, RateLimiter::attempts('spx-webhook-unverified:ip:203.0.113.9'));
        $this->assertSame(0, RateLimiter::attempts('spx-webhook-unverified:ip:127.0.0.1'));
    }
}
