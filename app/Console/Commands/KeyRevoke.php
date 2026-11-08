<?php

namespace App\Console\Commands;

use App\Models\ApiToken;
use App\Models\ClientApp;
use App\Platforms\PlatformApps;
use Illuminate\Console\Command;

class KeyRevoke extends Command
{
    protected $signature = 'ventaship:key-revoke {key : 8-character prefix or numeric id}';

    protected $description = 'Revoke an API key by its prefix or numeric id';

    public function handle(PlatformApps $apps): int
    {
        $arg = (string) $this->argument('key');
        $query = ApiToken::query()->where('tokenable_type', (new ClientApp)->getMorphClass());
        $keys = ctype_digit($arg) ? $query->whereKey((int) $arg)->get() : $query->where('prefix', $arg)->get();
        if ($keys->count() !== 1) {
            $this->error($keys->isEmpty() ? 'No such key.' : 'The prefix matches several keys; nothing revoked.');

            return self::FAILURE;
        }

        $key = $keys->first();
        if (! $apps->revokeKey($key)) {
            $this->warn('That key was already revoked.');

            return self::SUCCESS;
        }

        $this->info("Revoked {$key->prefix}.");

        return self::SUCCESS;
    }
}
