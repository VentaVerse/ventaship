<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One of Ventaship's own shipment statuses (shipments.phase holds its key). */
class ShipmentStatus extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'sort' => 'integer'];
    }

    public function getRouteKeyName(): string
    {
        return 'key';
    }
}
