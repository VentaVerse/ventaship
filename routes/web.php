<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\FulfillmentController;
use App\Http\Controllers\Webhooks\CourierWebhookController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'show'])->name('login');
    Route::post('login', [LoginController::class, 'store']);
});

Route::middleware('auth')->group(function () {
    Route::post('logout', [LoginController::class, 'destroy'])->name('logout');
    Route::get('/', [FulfillmentController::class, 'index'])->name('fulfillment');
});

// Courier webhooks: no session, cookies or CSRF (the whole `web` group is dropped), throttled per IP.
Route::withoutMiddleware('web')
    ->middleware('throttle:courier-webhooks')
    ->prefix('webhooks')
    ->group(function () {
        Route::post('quadx', [CourierWebhookController::class, 'quadx'])->name('webhooks.quadx');
        Route::post('spx/{token}', [CourierWebhookController::class, 'spx'])->name('webhooks.spx.token');
        Route::post('spx', [CourierWebhookController::class, 'spx'])->name('webhooks.spx');
    });
