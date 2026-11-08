<?php

namespace App\Providers;

use App\Couriers\Contracts\Courier;
use App\Couriers\CourierOrder;
use App\Couriers\CourierRegistry;
use App\Couriers\Lbc\LbcFulfillment;
use App\Couriers\Logging\RecordCourierCalls;
use App\Couriers\QuadX\QuadXFulfillment;
use App\Couriers\Spx\SpxFulfillment;
use App\Models\CourierAccount;
use App\Models\Shipment;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class CourierServiceProvider extends ServiceProvider
{
    /** @var array<string, class-string<Courier>> */
    private const COURIERS = [
        'quadx' => QuadXFulfillment::class,
        'spx' => SpxFulfillment::class,
        'lbc' => LbcFulfillment::class,
    ];

    public function boot(): void
    {
        foreach (self::COURIERS as $key => $cls) {
            CourierRegistry::register($key, $cls);
        }

        // A tracking number is only looked up inside the account that booked it: two accounts never see each
        // other's shipments, and an unsaved (schema-only) account finds nothing.
        CourierOrder::resolveTrackingUsing(static function (string $courier, string $tracking, ?CourierAccount $account): ?CourierOrder {
            if ($account === null || ! $account->exists || $tracking === '') {
                return null;
            }

            $shipment = Shipment::query()
                ->where('courier_account_id', $account->getKey())
                ->where('tracking_number', $tracking)
                ->latest('id')
                ->first();

            return $shipment ? CourierOrder::fromApi($shipment) : null;
        });

        Event::listen(ResponseReceived::class, [RecordCourierCalls::class, 'handleResponse']);
        Event::listen(ConnectionFailed::class, [RecordCourierCalls::class, 'handleFailure']);
    }
}
