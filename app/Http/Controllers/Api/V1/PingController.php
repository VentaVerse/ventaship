<?php

namespace App\Http\Controllers\Api\V1;

use App\Api\ClientContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class PingController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $app = ClientContext::app();

        return response()->json(['app' => ['id' => $app->public_id, 'name' => $app->name]]);
    }
}
