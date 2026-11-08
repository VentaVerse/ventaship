<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CourierStatusLabelSet extends Model
{
    protected $table = 'courier_status_labels';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['labels' => 'array', 'refreshed_at' => 'datetime'];
    }
}
