<?php

namespace App\Models;

use App\Shipments\PhaseResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class StatusMapping extends Model
{
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::saving(function (self $mapping): void {
            $phase = $mapping->phase;

            if ($phase !== null && ! in_array($phase, PhaseResolver::SPINE, true)) {
                throw ValidationException::withMessages([
                    'phase' => 'Phase must be one of: ' . implode(', ', PhaseResolver::SPINE) . '.',
                ]);
            }
        });
    }
}
