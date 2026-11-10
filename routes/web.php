<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Ui\AddressesController;
use App\Http\Controllers\Ui\ApiController;
use App\Http\Controllers\Ui\CourierAccountsController;
use App\Http\Controllers\Ui\CustomerSearchController;
use App\Http\Controllers\Ui\FulfillmentController;
use App\Http\Controllers\Ui\PhAddressController;
use App\Http\Controllers\Ui\SettingsController;
use App\Http\Controllers\Ui\ShipmentActionsController;
use App\Http\Controllers\Ui\ShipmentOptionsController;
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
    Route::get('shipments/options/spx-slots', [ShipmentOptionsController::class, 'spxSlots'])->name('shipments.options.spx-slots');
    Route::get('shipments/options/lbc-dates', [ShipmentOptionsController::class, 'lbcDates'])->name('shipments.options.lbc-dates');
    Route::get('shipments/{shipment:public_id}', [ShipmentsController::class, 'show'])->name('shipments.show');
    Route::get('shipments/{shipment:public_id}/edit', [ShipmentsController::class, 'edit'])->name('shipments.edit');
    Route::put('shipments/{shipment:public_id}', [ShipmentsController::class, 'update'])->name('shipments.update');
    Route::delete('shipments/{shipment:public_id}', [ShipmentsController::class, 'destroy'])->name('shipments.destroy');
    Route::post('shipments/book', [ShipmentActionsController::class, 'book'])->name('shipments.book');
    Route::post('shipments/cancel', [ShipmentActionsController::class, 'cancel'])->name('shipments.cancel');
    Route::post('shipments/{shipment:public_id}/retry', [ShipmentActionsController::class, 'retry'])->name('shipments.retry');
    Route::post('shipments/{shipment:public_id}/resolve', [ShipmentActionsController::class, 'resolve'])->name('shipments.resolve');
    Route::get('addresses', [AddressesController::class, 'index'])->name('addresses.index');
    Route::get('addresses/pickup/new', [AddressesController::class, 'createPickup'])->name('addresses.pickup.create');
    Route::post('addresses/pickup', [AddressesController::class, 'storePickup'])->name('addresses.pickup.store');
    Route::prefix('addresses/pickup/{pickup:public_id}')->group(function () {
        Route::get('edit', [AddressesController::class, 'editPickup'])->name('addresses.pickup.edit');
        Route::put('/', [AddressesController::class, 'updatePickup'])->name('addresses.pickup.update');
        Route::delete('/', [AddressesController::class, 'destroyPickup'])->name('addresses.pickup.destroy');
        Route::post('default', [AddressesController::class, 'makeDefault'])->name('addresses.pickup.default');
    });
    Route::get('customers/search', CustomerSearchController::class)->name('customers.search');
    Route::get('addresses/customers/new', [AddressesController::class, 'createCustomer'])->name('addresses.customers.create');
    Route::post('addresses/customers', [AddressesController::class, 'storeCustomer'])->name('addresses.customers.store');
    Route::prefix('addresses/customers/{customer:public_id}')->group(function () {
        Route::get('edit', [AddressesController::class, 'editCustomer'])->name('addresses.customers.edit');
        Route::put('/', [AddressesController::class, 'updateCustomer'])->name('addresses.customers.update');
        Route::delete('/', [AddressesController::class, 'destroyCustomer'])->name('addresses.customers.destroy');
    });
    Route::get('accounts', [CourierAccountsController::class, 'index'])->name('accounts.index');
    Route::prefix('accounts/{courier}')->where(['courier' => 'quadx|spx|lbc'])->group(function () {
        Route::get('/', [CourierAccountsController::class, 'show'])->name('accounts.show');
        Route::put('/', [CourierAccountsController::class, 'update'])->name('accounts.update');
        Route::post('test', [CourierAccountsController::class, 'test'])->name('accounts.test');
        Route::post('series', [CourierAccountsController::class, 'series'])->name('accounts.series');
        Route::post('secrets/{key}/reveal', [CourierAccountsController::class, 'reveal'])
            ->where('key', '[a-z0-9_]+')->middleware('throttle:secret-reveals')->name('accounts.reveal');
    });
    Route::get('developers', [ApiController::class, 'index'])->name('developers.index');
    Route::prefix('developers')->name('developers.')->group(function () {
        Route::post('keys', [ApiController::class, 'storeKey'])->name('keys.store');
        Route::prefix('apps/{app:public_id}')->group(function () {
            Route::post('roll', [ApiController::class, 'rollKey'])->name('keys.roll');
            Route::put('name', [ApiController::class, 'renameKey'])->name('keys.rename');
            Route::post('revoke', [ApiController::class, 'revokeKey'])->name('keys.revoke');
            Route::post('reveal/{what}', [ApiController::class, 'reveal'])
                ->where('what', 'token:\d{1,19}|webhook')->middleware('throttle:secret-reveals')->name('reveal');
        });
        Route::prefix('endpoints/{app:public_id}')->group(function () {
            Route::put('/', [ApiController::class, 'updateEndpoint'])->name('endpoints.update');
            Route::delete('/', [ApiController::class, 'destroyEndpoint'])->name('endpoints.destroy');
            Route::post('test', [ApiController::class, 'testEndpoint'])->name('endpoints.test');
            Route::post('secret', [ApiController::class, 'rollSecret'])->name('endpoints.secret');
        });
        Route::post('events/{event:public_id}/resend', [ApiController::class, 'resendEvent'])->name('events.resend');
    });
    // The old Connected platforms pages.
    Route::redirect('platforms', '/developers');
    Route::redirect('platforms/new', '/developers?tab=keys&create=1');
    Route::redirect('platforms/{any}', '/developers')->where('any', '[A-Za-z0-9_]+');
    Route::get('ph/{level}', PhAddressController::class)->where('level', 'provinces|cities|districts|barangays')->name('ph.options');
    Route::get('settings', [SettingsController::class, 'edit'])->name('settings.edit');
    Route::put('settings', [SettingsController::class, 'update'])->name('settings.update');
    Route::put('settings/account', [SettingsController::class, 'account'])->name('settings.account');
    Route::put('settings/password', [SettingsController::class, 'password'])->name('settings.password');
    Route::post('shipments/labels', [ShipmentActionsController::class, 'labels'])->name('shipments.labels');
    Route::get('shipments/labels/bundle', [ShipmentActionsController::class, 'bundle'])
        ->middleware('signed:relative')->name('shipments.labels.bundle');
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
