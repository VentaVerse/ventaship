<?php

namespace App\Http\Controllers\Ui;

use App\Addresses\CustomerRow;
use App\Addresses\Customers;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /customers/search?q=…: the shipment form's customer search. At most 8 matches (name or phone, at least 2
 * characters), each with only what the list shows and what the form fills; never the email or the shipment counts.
 */
class CustomerSearchController extends Controller
{
    public function __invoke(Request $request, Customers $customers): JsonResponse
    {
        $q = $request->query('q');

        $found = is_string($q) ? $customers->search($q, 8) : collect();

        return response()->json(['customers' => $found->map(fn (Customer $c) => CustomerRow::of($c))->values()]);
    }
}
