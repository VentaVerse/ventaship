<?php

namespace App\Http\Controllers\Api\V1;

use App\Api\ClientContext;
use App\Http\Controllers\Controller;
use App\Support\AppVersion;
use Illuminate\Http\JsonResponse;

class PingController extends Controller
{
    /** GET /ping: the key's app, and the Ventaship version answering (for a client's connection test). */
    public function __invoke(): JsonResponse
    {
        $app = ClientContext::app();

        return response()->json(['app' => ['id' => $app->public_id, 'name' => $app->name], 'version' => AppVersion::current()]);
    }
}
