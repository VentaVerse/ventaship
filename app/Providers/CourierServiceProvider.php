<?php

namespace App\Providers;

use App\Couriers\Contracts\Courier;
use App\Couriers\CourierRegistry;
use App\Couriers\Logging\RecordCourierCalls;
use App\Couriers\QuadX\QuadXFulfillment;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class CourierServiceProvider extends ServiceProvider
{
    /** @var array<string, class-string<Courier>> */
    private const COURIERS = [
        'quadx' => QuadXFulfillment::class,
        // 'spx' and 'lbc' are registered once their adapters take (CourierAccount, AccountSettings) (Task 8).
    ];

    public function boot(): void
    {
        foreach (self::COURIERS as $key => $cls) {
            CourierRegistry::register($key, $cls);
        }

        Event::listen(ResponseReceived::class, [RecordCourierCalls::class, 'handleResponse']);
        Event::listen(ConnectionFailed::class, [RecordCourierCalls::class, 'handleFailure']);
    }
}
