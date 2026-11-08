<?php

namespace App\Http\Controllers\Ui;

use App\Api\ApiError;
use App\Couriers\CourierAccounts;
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
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

class ShipmentsController extends Controller
{
    private const TOKENS_KEY = 'shipment_form_tokens';

    private const TOKENS_MAX = 20;

    private const DONE_PREFIX = 'shipment-form-done:';

    private const LOCK_SECONDS = 120;

    /** The courier tiles, in this order. */
    private const COURIERS = ['quadx', 'spx', 'lbc'];

    public function create(Request $request, Settings $settings): View
    {
        return $this->form($request, $settings, null);
    }

    public function edit(Request $request, Shipment $shipment, Settings $settings): View|RedirectResponse
    {
        if (! $shipment->isEditable()) {
            return $this->refused($shipment);
        }

        return $this->form($request, $settings, $shipment->loadMissing('courierAccount'));
    }

    public function update(ShipmentFormRequest $form, Shipment $shipment, ShipmentCreator $creator): RedirectResponse
    {
        if (! $shipment->isEditable()) {
            return $this->refused($shipment);
        }

        $data = $form->shipmentData(false);

        try {
            if (! $form->boolean('book')) {
                $this->auditUpdate($form, $creator->update($shipment, $data));

                return redirect()->route('shipments.show', $shipment->public_id)->with('status', 'Saved');
            }
        } catch (ApiError $e) {
            return $this->failed($e, $shipment);
        }

        return $this->once($form, function () use ($form, $creator, $shipment, $data) {
            $this->auditUpdate($form, $creator->update($shipment, $data));
            $booked = $creator->submit($shipment->refresh(), now: true);
            $this->audit($form, 'shipment.book_requested', $shipment);

            return $booked;
        }, $shipment);
    }

    public function destroy(Request $request, Shipment $shipment): RedirectResponse
    {
        if (! $shipment->isEditable()) {
            return $this->refused($shipment);
        }

        $courierRef = $shipment->courier_ref;
        $paths = [];
        $deleted = DB::transaction(function () use ($shipment, $request, $courierRef, &$paths) {
            $s = Shipment::query()->whereKey($shipment->getKey())->lockForUpdate()->first();
            if ($s === null || ! $s->isEditable()) {
                return false;
            }

            $paths = $s->labels()->pluck('path')->all();
            $s->events()->delete();
            $s->labels()->delete();
            $s->delete();

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

            return true;
        });

        if (! $deleted) {
            return $this->refused($shipment);
        }

        // Stored waybill files go once the rows are gone; a missing file is fine.
        Storage::disk('local')->delete($paths);

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
            $this->audit($form, 'shipment.created', $shipment, [
                'courier' => $shipment->courier,
                'source' => 'manual',
                'payment_method' => $shipment->payment_method,
            ]);

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
            return back()->withInput()->withErrors(['form_token' => 'Already submitting.']);
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
        // Another tab booked it between this request's save and its submit.
        if ($e->errorCode === 'not_draft' && $editing !== null) {
            return redirect()->route('shipments.show', $editing->public_id)->with('status', 'Already booked');
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

        return [
            'account_id' => $s->courierAccount?->public_id,
            'service' => $s->service,
            'client_reference' => $s->client_reference,
            'insure' => (bool) ($options['insure'] ?? false),
            'recipient' => $recipient,
            'items' => array_map(fn ($i) => [
                'name' => (string) ($i['name'] ?? ''),
                'quantity' => (string) ($i['quantity'] ?? '1'),
                'price' => (string) ($i['price'] ?? ''),
            ], array_values((array) $s->items)),
            'parcel' => (array) ($options['parcel'] ?? []),
            'payment' => ['method' => $s->payment_method === 'cod' ? 'cod' : 'prepaid'],
        ];
    }

    private function expired(): RedirectResponse
    {
        return back()->withInput()->withErrors(['form_token' => 'Form expired.']);
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

    /** @param  array<mixed>|null  $changes  never recipient or pickup data */
    private function audit(Request $request, string $action, Shipment $shipment, ?array $changes = null): void
    {
        AuditEntry::query()->create([
            'actor_type' => 'admin',
            'actor_id' => (string) $request->user()->getAuthIdentifier(),
            'ip' => $request->ip(),
            'action' => $action,
            'subject_type' => 'shipment',
            'subject_id' => $shipment->public_id,
            'changes' => $changes,
            'created_at' => now(),
        ]);
    }

    /** The names of the fields the edit changed, never their values. */
    private function auditUpdate(Request $request, Shipment $saved): void
    {
        $this->audit($request, 'shipment.updated', $saved, array_values(array_diff(array_keys($saved->getChanges()), ['updated_at'])));
    }

    /**
     * The add form, or the edit form for $editing. One tile per courier: its account from forCourier(), bookable when
     * active. An edit keeps its own account on its courier's tile (marked inactive) even when it is not active.
     */
    private function form(Request $request, Settings $settings, ?Shipment $editing): View
    {
        $own = $editing?->courierAccount;
        $tiles = [];
        $usable = new Collection;
        foreach (self::COURIERS as $courier) {
            $account = $own !== null && $own->courier === $courier ? $own : app(CourierAccounts::class)->forCourier($courier);
            $ok = $account !== null && ($account->status === 'active' || $account->is($own));
            if ($ok) {
                $usable->push($account);
            }
            $tiles[] = [
                'courier' => $courier,
                'name' => Couriers::name($courier),
                'id' => $ok ? $account->public_id : null,
                'inactive' => $account !== null && $account->status !== 'active',
            ];
        }

        $token = (string) Str::uuid();
        if ($usable->isNotEmpty()) {
            $this->rememberToken($request, $token, null);
        }

        return view('shipments.form', [
            'tiles' => $tiles,
            'services' => $this->servicesFor($usable),
            'sender' => $editing === null ? $settings->pickup() : ((array) $editing->pickup ?: null),
            'token' => $token,
            'editing' => $editing !== null,
            'shipment' => $editing,
            'prefill' => $editing === null ? [] : $this->prefill($editing),
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
