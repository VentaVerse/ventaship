<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Ui\FulfillmentController;
use App\Http\Controllers\Ui\SettingsController;
use App\Http\Controllers\Ui\ShipmentActionsController;
use App\Http\Controllers\Webhooks\CourierWebhookController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'show'])->name('login');
    Route::post('login', [LoginController::class, 'store']);
});

Route::middleware(['auth', 'auth.session'])->group(function () {
    Route::post('logout', [LoginController::class, 'destroy'])->name('logout');
    Route::get('/', [FulfillmentController::class, 'index'])->name('fulfillment');
    Route::get('fulfillment/poll', [FulfillmentController::class, 'poll'])->name('fulfillment.poll');
    Route::post('shipments/book', [ShipmentActionsController::class, 'book'])->name('shipments.book');
    Route::post('shipments/cancel', [ShipmentActionsController::class, 'cancel'])->name('shipments.cancel');
    Route::get('settings', [SettingsController::class, 'edit'])->name('settings.edit');
    Route::put('settings', [SettingsController::class, 'update'])->name('settings.update');
    Route::put('settings/account', [SettingsController::class, 'account'])->name('settings.account');
    Route::post('shipments/labels', [ShipmentActionsController::class, 'labels'])->name('shipments.labels');
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
