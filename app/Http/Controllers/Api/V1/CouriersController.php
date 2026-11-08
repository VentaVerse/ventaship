<?php

namespace App\Http\Controllers\Api\V1;

use App\Couriers\CourierCapabilities;
use App\Couriers\CourierRegistry;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class CouriersController extends Controller
{
    /** GET /couriers: every installed courier and what it can do. */
    public function index(): JsonResponse
    {
        $keys = CourierRegistry::keys();
        sort($keys);

        return response()->json(['data' => array_map(CourierCapabilities::describe(...), $keys)]);
    }
}
