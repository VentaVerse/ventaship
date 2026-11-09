<?php

namespace App\Http\Controllers\Ui;

use App\Addresses\Customers;
use App\Addresses\PickupAddresses;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\PickupAddress;
use App\Rules\PhMobile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AddressesController extends Controller
{
    private const PER_PAGE = 50;

    public function index(Request $request): View
    {
        $tab = $request->query('tab') === 'customers' ? 'customers' : 'pickup';
        $q = trim((string) $request->query('q', ''));

        if ($tab === 'pickup') {
            return view('addresses.index', [
                'tab' => $tab, 'q' => '', 'customers' => null,
                'pickups' => PickupAddress::query()->orderByDesc('is_default')->orderBy('label')->orderBy('id')->get(),
            ]);
        }

        $customers = Customer::query()
            ->when(mb_strlen($q) >= Customers::SEARCH_MIN, fn ($query) => app(Customers::class)->matching($query, $q))
            ->orderByDesc('last_shipped_at')->orderByDesc('id')
            ->paginate(self::PER_PAGE)->withQueryString();

        return view('addresses.index', ['tab' => $tab, 'q' => $q, 'customers' => $customers, 'pickups' => null]);
    }

    public function createPickup(): View
    {
        return view('addresses.pickup', ['pickup' => null, 'values' => []]);
    }

    public function storePickup(Request $request, PickupAddresses $pickups): RedirectResponse
    {
        $pickups->create($this->pickupData($request));

        return redirect()->route('addresses.index', ['tab' => 'pickup'])->with('status', 'Added');
    }

    public function editPickup(PickupAddress $pickup): View
    {
        $address = $pickup->toPickup();

        return view('addresses.pickup', ['pickup' => $pickup, 'values' => ['label' => $pickup->label] + $address]);
    }

    public function updatePickup(Request $request, PickupAddress $pickup, PickupAddresses $pickups): RedirectResponse
    {
        $pickups->update($pickup, $this->pickupData($request));

        return redirect()->route('addresses.index', ['tab' => 'pickup'])->with('status', 'Saved');
    }

    public function destroyPickup(PickupAddress $pickup, PickupAddresses $pickups): RedirectResponse
    {
        $pickups->delete($pickup);

        return redirect()->route('addresses.index', ['tab' => 'pickup'])->with('status', 'Deleted');
    }

    public function makeDefault(PickupAddress $pickup, PickupAddresses $pickups): RedirectResponse
    {
        $pickups->makeDefault($pickup);

        return redirect()->route('addresses.index', ['tab' => 'pickup'])->with('status', 'Default changed');
    }

    public function editCustomer(Customer $customer): View
    {
        return view('addresses.customer', ['customer' => $customer, 'values' => (array) $customer->address]);
    }

    public function createCustomer(): View
    {
        return view('addresses.customer', ['customer' => null, 'values' => []]);
    }

    public function storeCustomer(Request $request, Customers $customers): RedirectResponse
    {
        $customers->create($this->customerData($request));

        return redirect()->route('addresses.index', ['tab' => 'customers'])->with('status', 'Added');
    }

    public function updateCustomer(Request $request, Customer $customer, Customers $customers): RedirectResponse
    {
        // The form has no Email or Address line 2: a customer's stored ones (from its shipments) are kept.
        $stored = array_intersect_key((array) $customer->address, array_flip(['email', 'address_2']));
        $customers->update($customer, $this->customerData($request) + $stored);

        return redirect()->route('addresses.index', ['tab' => 'customers'])->with('status', 'Saved');
    }

    public function destroyCustomer(Customer $customer, Customers $customers): RedirectResponse
    {
        $customers->delete($customer);

        return redirect()->route('addresses.index', ['tab' => 'customers'])->with('status', 'Deleted');
    }

    /** @return array<string, mixed> */
    private function customerData(Request $request): array
    {
        $max = ['string', 'max:255'];

        $request->merge(['phone' => PhMobile::canonical($request->input('phone')) ?? $request->input('phone')]);

        return $this->flatten($request->validate([
            'first_name' => ['nullable', ...$max],
            'last_name' => ['nullable', ...$max],
            'phone' => ['required', 'string', new PhMobile],
            'address_1' => ['required', ...$max],
            'address' => ['required', 'array'],
            ...$this->addressRules(),
        ]));
    }

    /** @return array<string, mixed> */
    private function pickupData(Request $request): array
    {
        $max = ['string', 'max:255'];

        $request->merge(['contact_phone' => PhMobile::canonical($request->input('contact_phone')) ?? $request->input('contact_phone')]);

        return $this->flatten($request->validate([
            'label' => ['required', ...$max],
            'contact_name' => ['required', ...$max],
            'contact_phone' => ['required', 'string', new PhMobile],
            'address_1' => ['required', ...$max],
            'address_2' => ['nullable', ...$max],
            'address' => ['required', 'array'],
            ...$this->addressRules(),
        ]));
    }

    /** @return array<string, array<int, mixed>> the PSGC block, posted as address[...] */
    private function addressRules(): array
    {
        $max = ['string', 'max:255'];

        return [
            'address.state' => ['required', ...$max],
            'address.city' => ['required', ...$max],
            'address.district' => ['nullable', ...$max],
            'address.barangay' => ['nullable', ...$max],
            'address.postcode' => ['nullable', 'string', 'max:16'],
            'address.codes' => ['nullable', 'array'],
            'address.codes.*' => ['nullable', 'string', 'max:32'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed> the address block folded into the top-level fields
     */
    private function flatten(array $data): array
    {
        $block = (array) ($data['address'] ?? []);
        unset($data['address']);

        return $data + $block;
    }
}
