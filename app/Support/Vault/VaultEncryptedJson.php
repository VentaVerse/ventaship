<?php

namespace App\Support\Vault;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

class VaultEncryptedJson implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        return $value === null
            ? null
            : json_decode(app(CredentialVault::class)->decrypt($value), true, 512, JSON_THROW_ON_ERROR);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null
            ? null
            : app(CredentialVault::class)->encrypt(json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }
}
