<?php

namespace App\Models;

use App\Support\Vault\VaultEncrypted;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * A client app's API token (Sanctum). `token` is the SHA-256 of the secret part; `token_encrypted` is the exact
 * string the client sends (`{id}|vsk_…`, or `vsk_…` for a key issued before Sanctum), vault-encrypted, so the admin
 * screen can show it again. `prefix` is the first 8 characters of the secret part, for naming the key on screen.
 */
class ApiToken extends PersonalAccessToken
{
    protected $table = 'personal_access_tokens';

    protected $fillable = ['name', 'token', 'abilities', 'expires_at', 'prefix', 'token_encrypted'];

    protected $hidden = ['token', 'token_encrypted'];

    /** Not expired: the tokens that can still authenticate. */
    public function scopeUsable(Builder $query): void
    {
        $query->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    protected function casts(): array
    {
        return [
            'abilities' => 'json',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'token_encrypted' => VaultEncrypted::class,
        ];
    }
}
