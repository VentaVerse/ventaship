<?php

namespace App\Http\Controllers\Api\V1;

use App\Api\ClientContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CreateShipmentRequest;
use App\Http\Requests\Api\V1\ListShipmentsRequest;
use App\Http\Resources\ShipmentEventResource;
use App\Http\Resources\ShipmentResource;
use App\Models\Shipment;
use App\Shipments\BookingWaiter;
use App\Shipments\ShipmentCreator;
use App\Shipments\TrackingRefresher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ShipmentsController extends Controller
{
    private const PER_PAGE = 50;

    public function __construct(private readonly ShipmentCreator $creator) {}

    /** GET /shipments: the caller's shipments, newest first, filtered, 50 per page by cursor. */
    public function index(ListShipmentsRequest $request): AnonymousResourceCollection
    {
        $query = Shipment::query()
            ->where('client_app_id', ClientContext::app()->getKey())
            ->with('courierAccount');

        if (($ids = $request->ids()) !== null) {
            $query->whereIn('public_id', $ids);
        }
        if (($reference = $request->validated('client_reference')) !== null) {
            $query->where('client_reference', $reference);
        }
        if (($state = $request->validated('state')) !== null) {
            $query->where('state', $state);
        }
        if (($attention = $request->needsAttention()) !== null) {
            $query->where('needs_attention', $attention);
        }
        if (($since = $request->updatedSince()) !== null) {
            $query->where('updated_at', '>=', $since);
        }

        return ShipmentResource::collection($query->orderByDesc('id')->cursorPaginate(self::PER_PAGE)->withQueryString());
    }

    /**
     * POST /shipments: 202 queued (booked asynchronously), or 201 for a draft. No courier is called here.
     * `?wait=N` (N ≤ 20) holds the answer until the booking settles or N seconds pass.
     */
    public function store(CreateShipmentRequest $request, BookingWaiter $waiter): JsonResponse
    {
        $shipment = $this->creator->create(ClientContext::app(), $request->validated());
        $shipment->refresh();

        if ($shipment->state === 'queued' && ($seconds = BookingWaiter::seconds($request->query('wait'))) > 0) {
            $shipment = $waiter->wait($shipment, $seconds);
        }

        return (new ShipmentResource($shipment->load('courierAccount')))
            ->response()
            ->setStatusCode($shipment->state === 'draft' ? 201 : 202);
    }

    /** GET /shipments/{id}: the caller's own shipments only; anyone else's is a 404. */
    public function show(Request $request, string $shipment): ShipmentResource
    {
        $include = explode(',', (string) $request->query('include', ''));

        return (new ShipmentResource($this->find($shipment)))->withParties(in_array('parties', $include, true));
    }

    /** POST /shipments/{id}/submit: a draft becomes queued and is booked. */
    public function submit(string $shipment): JsonResponse
    {
        $submitted = $this->creator->submit($this->find($shipment));

        return (new ShipmentResource($submitted->refresh()->load('courierAccount')))->response()->setStatusCode(202);
    }

    /** GET /shipments/{id}/events: the timeline, oldest first. */
    public function events(string $shipment): AnonymousResourceCollection
    {
        $s = $this->find($shipment);

        return ShipmentEventResource::collection($s->events()->orderBy('occurred_at')->orderBy('id')->get());
    }

    /** POST /shipments/{id}/refresh: tracking fetched from the courier now (once a minute), with the timeline. */
    public function refresh(string $shipment, TrackingRefresher $refresher): ShipmentResource
    {
        $s = $refresher->refresh($this->find($shipment));

        return (new ShipmentResource($s->load('courierAccount')))->additional([
            'events' => ShipmentEventResource::collection($s->events()->orderBy('occurred_at')->orderBy('id')->get()),
        ]);
    }

    private function find(string $publicId): Shipment
    {
        return Shipment::query()
            ->where('client_app_id', ClientContext::app()->getKey())
            ->where('public_id', $publicId)
            ->with('courierAccount')
            ->firstOrFail();
    }
}
