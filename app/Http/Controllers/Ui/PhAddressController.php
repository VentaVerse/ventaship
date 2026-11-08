<?php

namespace App\Http\Controllers\Ui;

use App\Http\Controllers\Controller;
use App\Support\PhAddress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PhAddressController extends Controller
{
    public function __invoke(Request $request, PhAddress $ph, string $level): JsonResponse
    {
        $parent = (string) $request->query('parent', '');

        return response()->json(match ($level) {
            'provinces' => $ph->provinces(),
            'cities' => $ph->cities($parent),
            'districts' => $ph->districts($parent),
            'barangays' => $ph->barangays($parent),
        });
    }
}
