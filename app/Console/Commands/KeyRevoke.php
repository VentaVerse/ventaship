<?php

namespace App\Console\Commands;

use App\Models\ApiKey;
use App\Platforms\PlatformApps;
use Illuminate\Console\Command;

class KeyRevoke extends Command
{
    protected $signature = 'ventaship:key-revoke {prefix}';

    protected $description = 'Revoke an API key by its 8-character prefix';

    public function handle(PlatformApps $apps): int
    {
        $keys = ApiKey::query()->where('key_prefix', $this->argument('prefix'))->get();
        if ($keys->count() !== 1) {
            $this->error($keys->isEmpty() ? 'No key with that prefix.' : 'The prefix matches several keys; nothing revoked.');

            return self::FAILURE;
        }

        $key = $keys->first();
        if (! $apps->revokeKey($key)) {
            $this->warn('That key was already revoked.');

            return self::SUCCESS;
        }

        $this->info("Revoked {$key->key_prefix}.");

        return self::SUCCESS;
    }
}
