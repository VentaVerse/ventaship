<?php

use App\Http\Controllers\Api\V1\PingController;
use Illuminate\Support\Facades\Route;

Route::middleware('client.auth')->group(function () {
    Route::get('ping', PingController::class);
});
