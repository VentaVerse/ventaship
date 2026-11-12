<?php

namespace App\Http\Controllers\Ui;

use App\Api\ApiError;
use App\Fulfillment\Couriers;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ui\ResolveShipmentRequest;
use App\Models\AuditEntry;
use App\Models\Shipment;
use App\Shipments\BookingCheck;
use App\Shipments\BookingOutcomes;
use App\Shipments\Canceller;
use App\Shipments\LabelBundle;
use App\Shipments\ShipmentCreator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Throwable;

class ShipmentActionsController extends Controller
{
    private const ROW_ERROR = 'Something went wrong';

    private const BUNDLE_URL_MINUTES = 5;

    /**
     * Books drafts (the list's bulk Book and a draft page's Book). Each draft gets the courier's final check first: a
     * refusal leaves it a draft with the plain reason, never a failed shipment, and the others book.
     */
    public function book(Request $request, ShipmentCreator $creator, BookingCheck $check): RedirectResponse
    {
        [$shipments, $unknown] = $this->requested($request);
        $booked = 0;
        $refused = 0;
        $skipped = $unknown;
        $errors = [];

        foreach ($shipments as $s) {
            if ($s->state !== 'draft') {
                $skipped++;

                continue;
            }
            $name = $s->courier_ref ?: $s->public_id;
            try {
                $quote = $check->quoteStored($s);
                if ($quote->refusal !== null) {
                    $refused++;
                    $errors[] = "{$name}: {$quote->refusal->message()}";

                    continue;
                }
                $row = $creator->submit($s, now: true);
            } catch (ApiError $e) {
                if ($e->errorCode === 'not_draft') {
                    $skipped++;
                } else {
                    $errors[] = "{$name}: {$e->getMessage()}";
                }

                continue;
            } catch (Throwable $e) {
                $this->logRowError('Book', $s, $e);
                $errors[] = "{$name}: ".self::ROW_ERROR;

                continue;
            }
            $this->audit($request, 'shipment.book_requested', $s);

            // `booking` is SPX accepting the parcel while it assigns the tracking number.
            match ($row->state) {
                'booked', 'booking' => $booked++,
                'failed' => $errors[] = "{$name}: ".($row->error_message ?: 'The courier refused the booking.'),
                'unknown' => $errors[] = "{$name}: Booking result unknown",
                default => $errors[] = "{$name}: Booking did not start",
            };
        }

        $failed = count($errors) - $refused;
        $status = "{$booked} booked"
            .($refused > 0 ? " · {$refused} refused" : '')
            .($failed > 0 ? " · {$failed} failed" : '')
            .($skipped > 0 ? " · {$skipped} skipped" : '');

        return back()->with('status', $status)->withErrors($errors);
    }

    /**
     * The detail page's Retry: a refused booking sent to the courier again with the details it holds, through the same
     * path as a first booking (ShipmentCreator::retry, then BookShipment in this request). Refused when the shipment is
     * not `failed` or another live shipment of the same app holds its client reference; nothing changes then.
     */
    public function retry(Request $request, Shipment $shipment, ShipmentCreator $creator): RedirectResponse
    {
        $back = redirect()->route('shipments.show', $shipment->public_id);
        $name = $shipment->courier_ref ?: $shipment->public_id;

        try {
            $row = $creator->retry($shipment, now: true);
        } catch (ApiError $e) {
            return match ($e->errorCode) {
                'active_shipment_exists' => $this->heldBy($back, $shipment, $e),
                'not_failed' => $back->withErrors(['shipment' => "{$name} is no longer a failed booking."]),
                default => $back->withErrors(['shipment' => $e->getMessage()]),
            };
        } catch (Throwable $e) {
            $this->logRowError('Retry', $shipment, $e);

            return $back->withErrors(['shipment' => self::ROW_ERROR]);
        }

        $this->audit($request, 'shipment.retry_requested', $shipment);

        return match ($row->state) {
            'booked' => $back->with('status', "{$name} booked"),
            'booking' => $back->with('status', "{$name} sent to ".Couriers::name((string) $row->courier)),
            'failed' => $back->withErrors(['shipment' => "{$name}: ".($row->error_message ?: 'The courier refused the booking.')]),
            'unknown' => $back->withErrors(['shipment' => "{$name}: Booking result unknown"]),
            default => $back->withErrors(['shipment' => "{$name}: Booking did not start"]),
        };
    }

    /**
     * The detail page's resolution of an `unknown` booking, the same BookingOutcomes calls as
     * `ventaship:shipments-resolve` (outbox `shipment.resolved`), audited.
     */
    public function resolve(ResolveShipmentRequest $form, Shipment $shipment, BookingOutcomes $outcomes): RedirectResponse
    {
        $back = redirect()->route('shipments.show', $shipment->public_id);
        $name = $shipment->courier_ref ?: $shipment->public_id;
        $data = $form->validated();
        $notBooked = $data['outcome'] === 'not_booked';

        $resolved = $notBooked
            ? $outcomes->resolveNotBooked($shipment->id)
            : $outcomes->resolveBooked($shipment->id, (string) $data['tracking']);

        if ($resolved === null) {
            return $back->withErrors(['shipment' => "{$name} is no longer waiting for a booking result."]);
        }

        $this->audit($form, $notBooked ? 'shipment.resolved_not_booked' : 'shipment.resolved_booked', $shipment,
            $notBooked ? null : ['tracking_number' => $resolved->tracking_number]);

        return $back->with('status', $notBooked ? "{$name} marked not booked" : "{$name} marked booked");
    }

    /** "VentaCart already sent this order again as VS000123.", the holder linked. */
    private function heldBy(RedirectResponse $back, Shipment $shipment, ApiError $e): RedirectResponse
    {
        $holderId = $e->errors['shipment'] ?? null;
        $holder = is_string($holderId) ? Shipment::query()->where('public_id', $holderId)->first() : null;
        if ($holder === null) {
            return $back->withErrors(['shipment' => 'Another live shipment already holds this order reference.']);
        }

        $app = $shipment->clientApp?->name;

        return $back->with('flash_link', [
            'before' => $app !== null && $app !== ''
                ? "{$app} already sent this order again as "
                : 'This order reference is already on ',
            'label' => $holder->courier_ref ?: $holder->public_id,
            'href' => route('shipments.show', $holder->public_id),
            'after' => '.',
        ]);
    }

    public function cancel(Request $request, Canceller $canceller): RedirectResponse
    {
        [$shipments, $unknown] = $this->requested($request);
        $done = 0;
        $refused = $unknown;
        $errors = [];

        foreach ($shipments as $s) {
            $before = $s->cancel_state;
            try {
                $row = $canceller->request($s, now: true);
            } catch (ApiError) {
                $refused++;

                continue;
            } catch (Throwable $e) {
                $this->logRowError('Cancel', $s, $e);
                $refused++;
                $errors[] = ($s->courier_ref ?: $s->public_id).': '.self::ROW_ERROR;

                continue;
            }
            if ($row->cancel_state !== $before || $row->state !== $s->state) {
                $this->audit($request, 'shipment.cancel_requested', $s);
            }
            $row->cancel_state === 'cancelled' ? $done++ : $refused++;
        }

        return back()->with('status', "{$done} cancelled".($refused > 0 ? " · {$refused} not cancellable" : ''))->withErrors($errors);
    }

    public function labels(Request $request, LabelBundle $bundles): Response|RedirectResponse|JsonResponse
    {
        if ($request->expectsJson()) {
            return $this->labelsJson($request, $bundles);
        }

        try {
            $bundle = $bundles->build($this->shipments($request));
        } catch (ApiError $e) {
            return back()->withErrors(['ids' => $e->errorCode === 'label_not_ready'
                ? 'None of these have a waybill yet.'
                : $e->getMessage()]);
        }

        if ($bundle['skipped'] !== []) {
            session()->put('notice', count($bundle['skipped']).' skipped: no waybill yet');
        }

        // a PDF opens in the browser's viewer (the no-JS form targets a new tab); a ZIP can only be saved
        return $this->pdfResponse($bundle);
    }

    /**
     * The page's print: every waybill is fetched (and stored) now, the answer says which printed and why the others
     * did not, and `url` is a short-lived signed link, for this admin only, that serves the printed ones as one PDF.
     */
    private function labelsJson(Request $request, LabelBundle $bundles): JsonResponse
    {
        [$shipments, , $ids] = $this->requested($request);
        ['paths' => $paths, 'failed' => $errors] = $bundles->collect($shipments);
        $byId = $shipments->keyBy('public_id');

        $failed = [];
        foreach ($ids as $id) {
            $s = $byId->get($id);
            if ($s === null) {
                $failed[] = ['id' => $id, 'name' => null, 'tracking' => null, 'reason' => 'Not found.'];
            } elseif (isset($errors[$id])) {
                $failed[] = [
                    'id' => $id,
                    'name' => $this->recipientName($s) ?? ($s->courier_ref ?: $id),
                    'tracking' => $s->tracking_number ?: null,
                    'reason' => $this->labelReason($s, $errors[$id]),
                ];
            }
        }

        $ok = array_keys($paths);

        return response()->json([
            'ok' => $ok,
            'failed' => $failed,
            'url' => $ok === [] ? null : URL::temporarySignedRoute('shipments.labels.bundle', now()->addMinutes(self::BUNDLE_URL_MINUTES), [
                'ids' => implode(',', $ok),
                'u' => (string) $request->user()->getAuthIdentifier(),
            ], absolute: false),
        ], 200, ['Cache-Control' => 'private, no-store']);
    }

    /** The signed link from labelsJson(): the stored waybills of those shipments as one inline PDF (or a ZIP). */
    public function bundle(Request $request, LabelBundle $bundles): Response
    {
        abort_unless(hash_equals((string) $request->user()->getAuthIdentifier(), (string) $request->query('u', '')), 403);

        $ids = array_slice(array_values(array_unique(array_filter(
            explode(',', (string) $request->query('ids', '')),
            fn (string $id) => $id !== '' && strlen($id) <= 64,
        ))), 0, 100);
        abort_if($ids === [], 404);

        $found = Shipment::query()->whereIn('public_id', $ids)->with('courierAccount')->get()->keyBy('public_id');

        try {
            $bundle = $bundles->build(collect($ids)->map(fn ($id) => $found->get($id))->filter()->values());
        } catch (ApiError) {
            abort(404);
        }

        return $this->pdfResponse($bundle);
    }

    /** @param  array{bytes: string, type: string}  $bundle */
    private function pdfResponse(array $bundle): Response
    {
        return response($bundle['bytes'], 200, [
            'Content-Type' => $bundle['type'] === 'pdf' ? 'application/pdf' : 'application/zip',
            // a PDF opens in the browser's viewer; a ZIP can only be saved
            'Content-Disposition' => ($bundle['type'] === 'pdf' ? 'inline' : 'attachment').'; filename="waybills.'.$bundle['type'].'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function labelReason(Shipment $s, ApiError $e): string
    {
        if ($e->errorCode === 'label_not_ready') {
            return 'No waybill yet.';
        }
        $courier = Couriers::name((string) ($s->courier ?: $s->courierAccount?->courier));

        return $e->retryable
            ? "{$courier} didn't return the waybill. Try again in a minute."
            : "{$courier} didn't return the waybill.";
    }

    private function recipientName(Shipment $s): ?string
    {
        $r = $s->recipient;
        $name = is_array($r) ? trim(((string) ($r['first_name'] ?? '')).' '.((string) ($r['last_name'] ?? ''))) : '';

        return $name === '' ? null : $name;
    }

    /** @return Collection<int, Shipment> the requested shipments in request order; unknown ids are dropped */
    private function shipments(Request $request): Collection
    {
        return $this->requested($request)[0];
    }

    /** @return array{0: Collection<int, Shipment>, 1: int, 2: list<string>} the known shipments in request order, how many ids were unknown, and the distinct ids */
    private function requested(Request $request): array
    {
        $ids = array_values(array_unique($request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['required', 'string', 'max:64'],
        ])['ids']));

        $found = Shipment::query()->whereIn('public_id', $ids)->with('courierAccount')->get()->keyBy('public_id');
        $shipments = collect($ids)->map(fn ($id) => $found->get($id))->filter()->values();

        return [$shipments, count($ids) - $shipments->count(), $ids];
    }

    /** Class and message only (DB/runtime errors); the shipment row itself, with its recipient, is never logged. */
    private function logRowError(string $action, Shipment $s, Throwable $e): void
    {
        Log::error("{$action} failed for one shipment", [
            'shipment' => $s->public_id, 'exception' => $e::class, 'message' => mb_substr($e->getMessage(), 0, 500),
        ]);
    }

    /** @param  array<string, mixed>|null  $changes  never recipient or pickup data */
    private function audit(Request $request, string $action, Shipment $s, ?array $changes = null): void
    {
        AuditEntry::query()->create([
            'actor_type' => 'admin',
            'actor_id' => (string) $request->user()->getAuthIdentifier(),
            'ip' => $request->ip(),
            'action' => $action,
            'subject_type' => 'shipment',
            'subject_id' => $s->public_id,
            'changes' => $changes,
            'created_at' => now(),
        ]);
    }
}
