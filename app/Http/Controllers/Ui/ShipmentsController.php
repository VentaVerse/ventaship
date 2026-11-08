<?php

namespace App\Http\Controllers\Ui;

use App\Api\ApiError;
use App\Couriers\CourierRegistry;
use App\Fulfillment\Couriers;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ui\ShipmentFormRequest;
use App\Models\AuditEntry;
use App\Models\CourierAccount;
use App\Models\Shipment;
use App\Shipments\ShipmentCreator;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

class ShipmentsController extends Controller
{
    private const TOKENS_KEY = 'shipment_form_tokens';

    private const TOKENS_MAX = 20;

    private const LOCK_SECONDS = 90;

    public function create(Request $request, Settings $settings): View
    {
        $accounts = CourierAccount::query()->where('status', 'active')->orderBy('courier')->orderBy('label')->get();

        $services = [];
        foreach ($accounts as $account) {
            $services[$account->public_id] = $this->services((string) $account->courier);
        }

        $token = (string) Str::uuid();
        if ($accounts->isNotEmpty()) {
            $this->rememberToken($request, $token, null);
        }

        return view('shipments.form', [
            'accounts' => $accounts->map(fn (CourierAccount $a) => [
                'id' => $a->public_id,
                'label' => Couriers::name((string) $a->courier).' · '.$a->label,
            ])->all(),
            'services' => $services,
            'sender' => $settings->pickup() ?? [],
            'token' => $token,
        ]);
    }

    public function store(ShipmentFormRequest $form, ShipmentCreator $creator): RedirectResponse
    {
        $request = $form;
        $token = (string) $request->input('form_token');
        $tokens = (array) $request->session()->get(self::TOKENS_KEY, []);

        if (! array_key_exists($token, $tokens)) {
            return $this->expired();
        }
        if ($tokens[$token] !== null) {
            return $this->toShipment($tokens[$token], null);
        }

        // A second request for the same render, while the first still waits on the courier, must not book again.
        $lock = Cache::lock('shipment-form:'.$token, self::LOCK_SECONDS);
        if (! $lock->get()) {
            return back()->withInput()->withErrors(['form_token' => 'Already submitting. Check the shipment list before trying again.']);
        }

        try {
            try {
                $shipment = $creator->create(null, $form->validated(), 'manual', now: true);
            } catch (ApiError $e) {
                return back()->withInput()->withErrors($this->apiErrors($e));
            }

            $this->rememberToken($request, $token, $shipment->public_id);
            $this->audit($request, $shipment);
        } finally {
            $lock->release();
        }

        return $this->toShipment($shipment->public_id, $this->outcome($shipment));
    }

    private function expired(): RedirectResponse
    {
        return back()->withInput()->withErrors(['form_token' => 'This form expired. Check the shipment list before trying again.']);
    }

    private function toShipment(string $publicId, ?string $status): RedirectResponse
    {
        $redirect = Route::has('shipments.show')
            ? redirect()->route('shipments.show', $publicId)
            : redirect()->route('fulfillment');

        return $status === null ? $redirect : $redirect->with('status', $status);
    }

    private function outcome(Shipment $shipment): string
    {
        return match ($shipment->state) {
            'booked' => 'Booked · '.$shipment->tracking_number,
            'failed' => 'Courier refused: '.$shipment->error_message,
            'unknown' => 'Booking result unknown',
            default => 'Booking pending',
        };
    }

    /** @return array<string, string> */
    private function apiErrors(ApiError $e): array
    {
        return match ($e->errorCode) {
            'active_shipment_exists' => ['client_reference' => 'Already in use.'],
            'account_not_found' => ['account_id' => 'Not available.'],
            'account_misconfigured' => ['account_id' => 'Not ready to book.'],
            default => ['account_id' => 'Not valid.'],
        };
    }

    private function rememberToken(Request $request, string $token, ?string $publicId): void
    {
        $tokens = (array) $request->session()->get(self::TOKENS_KEY, []);
        unset($tokens[$token]);
        $tokens[$token] = $publicId;
        $request->session()->put(self::TOKENS_KEY, array_slice($tokens, -self::TOKENS_MAX, null, true));
    }

    private function audit(Request $request, Shipment $shipment): void
    {
        AuditEntry::query()->create([
            'actor_type' => 'admin',
            'actor_id' => (string) $request->user()->getAuthIdentifier(),
            'ip' => $request->ip(),
            'action' => 'shipment.created',
            'subject_type' => 'shipment',
            'subject_id' => $shipment->public_id,
            'changes' => [
                'courier' => $shipment->courier,
                'source' => 'manual',
                'payment_method' => $shipment->payment_method,
            ],
            'created_at' => now(),
        ]);
    }

    /** @return list<array{value: string, label: string}> */
    private function services(string $courier): array
    {
        try {
            return array_values(array_map(
                fn (array $s) => ['value' => (string) $s['value'], 'label' => (string) $s['label']],
                CourierRegistry::describe($courier)->getServiceTypes(),
            ));
        } catch (Throwable) {
            return [];
        }
    }
}
