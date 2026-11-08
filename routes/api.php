<?php

use App\Http\Controllers\Api\V1\CourierAccountsController;
use App\Http\Controllers\Api\V1\CouriersController;
use App\Http\Controllers\Api\V1\CoverageController;
use App\Http\Controllers\Api\V1\PickupSlotsController;
use App\Http\Controllers\Api\V1\PingController;
use App\Http\Controllers\Api\V1\QuotesController;
use App\Http\Controllers\Api\V1\ShipmentsController;
use Illuminate\Support\Facades\Route;

// client.auth runs first (it sits in the framework's auth priority slot), so every limiter is keyed by the API key.
Route::middleware(['client.auth', 'throttle:api'])->group(function () {
    Route::get('ping', PingController::class);

    Route::get('couriers', [CouriersController::class, 'index']);
    Route::get('courier-accounts', [CourierAccountsController::class, 'index']);
    Route::get('courier-accounts/{account}', [CourierAccountsController::class, 'show']);

    Route::middleware('throttle:api-coverage')->group(function () {
        Route::post('coverage', CoverageController::class);
        Route::post('quotes', QuotesController::class);
        Route::post('pickup-slots', PickupSlotsController::class);
    });

    Route::post('shipments', [ShipmentsController::class, 'store'])->middleware(['throttle:api-create', 'idempotent']);
    Route::get('shipments/{shipment}', [ShipmentsController::class, 'show']);
    Route::post('shipments/{shipment}/submit', [ShipmentsController::class, 'submit'])->middleware('idempotent');
});
