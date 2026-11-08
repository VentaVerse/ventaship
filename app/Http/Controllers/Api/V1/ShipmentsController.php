<?php

namespace App\Http\Controllers\Api\V1;

use App\Api\ClientContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CreateShipmentRequest;
use App\Http\Resources\ShipmentResource;
use App\Models\Shipment;
use App\Shipments\ShipmentCreator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShipmentsController extends Controller
{
    public function __construct(private readonly ShipmentCreator $creator) {}

    /** POST /shipments: 202 queued (booked asynchronously), or 201 for a draft. No courier is called. */
    public function store(CreateShipmentRequest $request): JsonResponse
    {
        $shipment = $this->creator->create(ClientContext::app(), $request->validated());

        return (new ShipmentResource($shipment->refresh()->load('courierAccount')))
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

    private function find(string $publicId): Shipment
    {
        return Shipment::query()
            ->where('client_app_id', ClientContext::app()->getKey())
            ->where('public_id', $publicId)
            ->with(['courierAccount' => fn ($q) => $q->withTrashed()])
            ->firstOrFail();
    }
}
