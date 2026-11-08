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
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

class ShipmentsController extends Controller
{
    private const TOKENS_KEY = 'shipment_form_tokens';

    private const TOKENS_MAX = 20;

    private const DONE_PREFIX = 'shipment-form-done:';

    private const LOCK_SECONDS = 120;

    public function create(Request $request, Settings $settings): View
    {
        $accounts = CourierAccount::query()->where('status', 'active')->orderBy('courier')->orderBy('label')->get();

        $token = (string) Str::uuid();
        if ($accounts->isNotEmpty()) {
            $this->rememberToken($request, $token, null);
        }

        return view('shipments.form', [
            'accounts' => $accounts->map(fn (CourierAccount $a) => [
                'id' => $a->public_id,
                'label' => Couriers::name((string) $a->courier).' · '.$a->label,
            ])->all(),
            'services' => $this->servicesFor($accounts),
            'sender' => $settings->pickup() ?? [],
            'token' => $token,
            'editing' => false,
            'shipment' => null,
            'prefill' => [],
        ]);
    }

    public function edit(Request $request, Shipment $shipment, Settings $settings): View|RedirectResponse
    {
        if (! $shipment->isEditable()) {
            return $this->refused($shipment);
        }

        return $this->create($request, $settings)->with([
            'editing' => true,
            'shipment' => $shipment->loadMissing('courierAccount'),
            'prefill' => $this->prefill($shipment->loadMissing('courierAccount')),
        ]);
    }

    public function update(ShipmentFormRequest $form, Shipment $shipment, ShipmentCreator $creator): RedirectResponse
    {
        if (! $shipment->isEditable()) {
            return $this->refused($shipment);
        }

        $data = $form->shipmentData(false);

        try {
            if (! $form->boolean('book')) {
                $creator->update($shipment, $data);

                return redirect()->route('shipments.show', $shipment->public_id)->with('status', 'Saved');
            }
        } catch (ApiError $e) {
            return $this->failed($e, $shipment);
        }

        return $this->once($form, function () use ($creator, $shipment, $data) {
            $creator->update($shipment, $data);

            return $creator->submit($shipment->refresh(), now: true);
        }, $shipment);
    }

    public function destroy(Request $request, Shipment $shipment): RedirectResponse
    {
        if (! $shipment->isEditable()) {
            return $this->refused($shipment);
        }

        $courierRef = $shipment->courier_ref;
        $deleted = DB::transaction(function () use ($shipment) {
            $s = Shipment::query()->whereKey($shipment->getKey())->lockForUpdate()->first();
            if ($s === null || ! $s->isEditable()) {
                return false;
            }

            $s->events()->delete();
            $s->labels()->delete();
            $s->delete();

            return true;
        });

        if (! $deleted) {
            return $this->refused($shipment);
        }

        AuditEntry::query()->create([
            'actor_type' => 'admin',
            'actor_id' => (string) $request->user()->getAuthIdentifier(),
            'ip' => $request->ip(),
            'action' => 'shipment.deleted',
            'subject_type' => 'shipment',
            'subject_id' => $shipment->public_id,
            'changes' => ['courier_ref' => $courierRef],
            'created_at' => now(),
        ]);

        return redirect()->route('fulfillment')->with('status', 'Deleted');
    }

    public function show(Shipment $shipment): View
    {
        $shipment->load(['clientApp', 'courierAccount', 'events' => fn ($q) => $q->orderByDesc('occurred_at')->orderByDesc('id')]);

        return view('shipments.show', ['shipment' => $shipment]);
    }

    public function store(ShipmentFormRequest $form, ShipmentCreator $creator): RedirectResponse
    {
        return $this->once($form, function () use ($form, $creator) {
            $shipment = $creator->create(null, $form->shipmentData(), 'manual', now: true);
            $this->audit($form, $shipment);

            return $shipment;
        });
    }

    /**
     * Runs a booking at most once per rendered form: the token is remembered in the session and, because the session
     * is only saved after the response, also in the cache where a concurrent request sees it.
     *
     * @param  Closure(): Shipment  $work
     */
    private function once(ShipmentFormRequest $request, Closure $work, ?Shipment $editing = null): RedirectResponse
    {
        $token = (string) $request->input('form_token');
        $tokens = (array) $request->session()->get(self::TOKENS_KEY, []);

        if (is_string($done = Cache::get(self::DONE_PREFIX.$token))) {
            return $this->toShipment($done, null);
        }
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
            if (is_string($done = Cache::get(self::DONE_PREFIX.$token))) {
                return $this->toShipment($done, null);
            }

            try {
                $shipment = $work();
            } catch (ApiError $e) {
                return $this->failed($e, $editing);
            }

            Cache::put(self::DONE_PREFIX.$token, $shipment->public_id, now()->addDay());
            $this->rememberToken($request, $token, $shipment->public_id);
        } finally {
            $lock->release();
        }

        return $this->toShipment($shipment->public_id, $this->outcome($shipment));
    }

    private function failed(ApiError $e, ?Shipment $editing): RedirectResponse
    {
        if ($e->errorCode === 'not_editable' && $editing !== null) {
            return $this->refused($editing);
        }

        return back()->withInput()->withErrors($this->apiErrors($e));
    }

    private function refused(Shipment $shipment): RedirectResponse
    {
        return redirect()->route('shipments.show', $shipment->public_id)
            ->withErrors(['shipment' => 'Only unbooked manual shipments can be changed.']);
    }

    /** @param  Collection<int, CourierAccount>  $accounts @return array<string, list<array{value: string, label: string}>> */
    private function servicesFor(Collection $accounts): array
    {
        $services = [];
        foreach ($accounts as $account) {
            $services[$account->public_id] = $this->services((string) $account->courier);
        }

        return $services;
    }

    /** @return array<string, mixed> the form's field values for a stored shipment */
    private function prefill(Shipment $s): array
    {
        $options = (array) $s->options;
        $recipient = (array) $s->recipient;
        $pickup = (array) $s->pickup;

        return [
            'account_id' => $s->courierAccount?->public_id,
            'service' => $s->service,
            'client_reference' => $s->client_reference,
            'insure' => (bool) ($options['insure'] ?? false),
            'recipient' => $recipient,
            'pickup' => $pickup,
            'items' => array_map(fn ($i) => [
                'name' => (string) ($i['name'] ?? ''),
                'quantity' => (string) ($i['quantity'] ?? '1'),
                'price' => (string) ($i['price'] ?? ''),
            ], array_values((array) $s->items)),
            'parcel' => (array) ($options['parcel'] ?? []),
            'payment' => [
                'method' => $s->payment_method === 'cod' ? 'cod' : 'prepaid',
                'cod_amount' => $s->cod_amount,
                'declared_value' => $s->declared_value,
            ],
        ];
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
