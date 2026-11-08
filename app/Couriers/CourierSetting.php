<?php

namespace App\Couriers;

use Illuminate\Database\Eloquent\Model;

/**
 * One courier setting: the operator's courier accounts and options, keyed exactly as VentaCart's courier-fulfillment
 * keys them under settings(group=courier) (`quadx_api_key_*`, `spx_app_id`, `lbc_*`, `{key}_enabled`, ...), so the
 * ported courier code reads the same names. Secret values are stored encrypted (Crypt), see Support\CourierSecrets.
 */
class CourierSetting extends Model
{
    protected $table = 'courier_settings';

    protected $fillable = ['key', 'value'];

    public static function read(string $key): ?string
    {
        return static::where('key', $key)->value('value');
    }

    public static function write(string $key, ?string $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
