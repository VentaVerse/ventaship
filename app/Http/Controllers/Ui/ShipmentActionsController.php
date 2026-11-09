<?php

namespace App\Http\Controllers\Ui;

use App\Api\ApiError;
use App\Fulfillment\Couriers;
use App\Http\Controllers\Controller;
use App\Models\AuditEntry;
use App\Models\Shipment;
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

    public function book(Request $request, ShipmentCreator $creator): RedirectResponse
    {
        [$shipments, $unknown] = $this->requested($request);
        $booked = 0;
        $skipped = $unknown;
        $errors = [];

        foreach ($shipments as $s) {
            if ($s->state !== 'draft') {
                $skipped++;

                continue;
            }
            $name = $s->courier_ref ?: $s->public_id;
            try {
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

        $failed = count($errors);
        $status = "{$booked} booked"
            .($failed > 0 ? " · {$failed} failed" : '')
            .($skipped > 0 ? " · {$skipped} skipped" : '');

        return back()->with('status', $status)->withErrors($errors);
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

    private function audit(Request $request, string $action, Shipment $s): void
    {
        AuditEntry::query()->create([
            'actor_type' => 'admin',
            'actor_id' => (string) $request->user()->getAuthIdentifier(),
            'ip' => $request->ip(),
            'action' => $action,
            'subject_type' => 'shipment',
            'subject_id' => $s->public_id,
            'created_at' => now(),
        ]);
    }
}
