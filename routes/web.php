<?php

use App\Http\Controllers\Webhooks\CourierWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
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
