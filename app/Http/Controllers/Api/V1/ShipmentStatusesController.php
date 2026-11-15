<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ShipmentStatus;
use Illuminate\Http\JsonResponse;

class ShipmentStatusesController extends Controller
{
    /** GET /shipment-statuses: the owner's shipment statuses (the values `phase` carries), disabled ones too, in order. */
    public function index(): JsonResponse
    {
        $rows = ShipmentStatus::query()->orderBy('sort')->orderBy('id')->get()
            ->map(fn (ShipmentStatus $s) => ['key' => $s->key, 'name' => $s->name, 'enabled' => $s->enabled, 'sort_order' => $s->sort])
            ->values();

        return response()->json(['data' => $rows]);
    }
}
