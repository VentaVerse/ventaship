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

    protected function tearDown(): void
    {
        CourierRegistry::clearFakes();
        CourierStatusLabel::forget();
        CourierOrder::resolveTrackingUsing(null);

        parent::tearDown();
    }
}
