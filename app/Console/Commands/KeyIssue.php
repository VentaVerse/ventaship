<?php

namespace App\Console\Commands;

use App\Models\ApiKey;
use App\Models\ClientApp;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class KeyIssue extends Command
{
    protected $signature = 'ventaship:key-issue {app} {--name=}';

    protected $description = 'Issue an API key and signing secret for a client app (shown once)';

    public function handle(): int
    {
        $app = ClientApp::query()->where('public_id', $this->argument('app'))->first();
        if ($app === null) {
            $this->error('No such app.');

            return self::FAILURE;
        }

        $key = 'vsk_' . Str::random(40);
        $secret = Str::random(48);
        ApiKey::query()->create([
            'client_app_id' => $app->getKey(),
            'name' => $this->option('name') ?: 'Default',
            'key_prefix' => substr($key, 0, 8),
            'key_hash' => hash('sha256', $key),
            'signing_secret' => $secret,
        ]);

        $this->line("API key:        {$key}");
        $this->line("Signing secret: {$secret}");
        $this->warn('These are shown once. Store them now.');

        return self::SUCCESS;
    }
}
