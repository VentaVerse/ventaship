<?php

namespace App\Models;

use App\Support\Vault\VaultEncrypted;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApiKey extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['key_hash', 'key_encrypted', 'signing_secret'];

    protected function casts(): array
    {
        return [
            'key_encrypted' => VaultEncrypted::class,
            'signing_secret' => VaultEncrypted::class,
            'scopes' => 'array',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function clientApp(): BelongsTo
    {
        return $this->belongsTo(ClientApp::class);
    }
}
