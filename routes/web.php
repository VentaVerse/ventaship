<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Ui\CourierAccountsController;
use App\Http\Controllers\Ui\FulfillmentController;
use App\Http\Controllers\Ui\SettingsController;
use App\Http\Controllers\Ui\ShipmentActionsController;
use App\Http\Controllers\Ui\ShipmentsController;
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
    Route::get('shipments/new', [ShipmentsController::class, 'create'])->name('shipments.create');
    Route::post('shipments', [ShipmentsController::class, 'store'])->name('shipments.store');
    Route::get('shipments/{shipment:public_id}', [ShipmentsController::class, 'show'])->name('shipments.show');
    Route::get('shipments/{shipment:public_id}/edit', [ShipmentsController::class, 'edit'])->name('shipments.edit');
    Route::put('shipments/{shipment:public_id}', [ShipmentsController::class, 'update'])->name('shipments.update');
    Route::delete('shipments/{shipment:public_id}', [ShipmentsController::class, 'destroy'])->name('shipments.destroy');
    Route::post('shipments/book', [ShipmentActionsController::class, 'book'])->name('shipments.book');
    Route::post('shipments/cancel', [ShipmentActionsController::class, 'cancel'])->name('shipments.cancel');
    Route::get('accounts', [CourierAccountsController::class, 'index'])->name('accounts.index');
    Route::get('accounts/new', [CourierAccountsController::class, 'create'])->name('accounts.create');
    Route::post('accounts', [CourierAccountsController::class, 'store'])->name('accounts.store');
    Route::get('accounts/{account:public_id}', [CourierAccountsController::class, 'show'])->name('accounts.show');
    Route::put('accounts/{account:public_id}', [CourierAccountsController::class, 'update'])->name('accounts.update');
    Route::post('accounts/{account:public_id}/test', [CourierAccountsController::class, 'test'])->name('accounts.test');
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
