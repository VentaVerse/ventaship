<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Requires `const PUBLIC_PREFIX` on the model (e.g. "shp_").
 */
trait HasPublicId
{
    public static function bootHasPublicId(): void
    {
        static::creating(function ($model) {
            if (empty($model->public_id)) {
                $model->public_id = static::PUBLIC_PREFIX . strtolower((string) Str::ulid());
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
