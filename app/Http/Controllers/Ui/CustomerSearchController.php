<?php

namespace App\Http\Controllers\Ui;

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
    private const FILL = ['first_name', 'last_name', 'phone', 'address_1', 'address_2', 'barangay', 'district', 'city', 'state', 'postcode'];

    public function __invoke(Request $request, Customers $customers): JsonResponse
    {
        $q = $request->query('q');

        $found = is_string($q) ? $customers->search($q, 8) : collect();

        return response()->json(['customers' => $found->map(fn (Customer $c) => $this->row($c))->values()]);
    }

    /** @return array<string, mixed> */
    private function row(Customer $customer): array
    {
        $address = (array) $customer->address;
        $fill = [];
        foreach (self::FILL as $key) {
            $value = $address[$key] ?? null;
            $fill[$key] = is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
        }
        $fill['phone'] ??= $customer->phone;
        $codes = [];
        foreach (['state', 'city', 'barangay'] as $level) {
            $code = $address['codes'][$level] ?? null;
            if (is_scalar($code) && (string) $code !== '') {
                $codes[$level] = (string) $code;
            }
        }
        $fill['codes'] = (object) $codes;

        return [
            'public_id' => $customer->public_id,
            'name' => $customer->name,
            'phone' => $customer->phone,
            'summary' => implode(', ', array_filter([$fill['district'] ?? $fill['barangay'], $fill['city']])),
            'address' => $fill,
        ];
    }
}
