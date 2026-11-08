<?php

namespace Tests;

use App\Couriers\CourierOrder;
use App\Couriers\CourierRegistry;
use App\Couriers\CourierStatusLabel;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        Http::preventStrayRequests();
    }

    /**
     * Each real request is a fresh process; in a test the app lives on. So the sanctum guard's resolved caller is
     * forgotten before every request (else a second call is answered as the first caller), and the default guard that
     * auth:sanctum switches to is put back afterwards (else the next admin request looks for a token).
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $guard = config('auth.defaults.guard');
        // A request with a bearer token must be resolved afresh, not answered as the previous caller.
        if (isset($server['HTTP_AUTHORIZATION'])) {
            $this->app['auth']->guard('sanctum')->forgetUser();
        }

        try {
            return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
        } finally {
            $this->app['auth']->shouldUse($guard);
        }
    }

    protected function tearDown(): void
    {
        CourierRegistry::clearFakes();
        CourierStatusLabel::forget();
        CourierOrder::resolveTrackingUsing(null);

        parent::tearDown();
    }
}
