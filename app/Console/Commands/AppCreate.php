<?php

namespace App\Console\Commands;

use App\Models\ClientApp;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class AppCreate extends Command
{
    protected $signature = 'ventaship:app-create {name} {--webhook-url=}';

    protected $description = 'Create a client app and print its webhook secret (shown once)';

    public function handle(): int
    {
        $url = $this->option('webhook-url') ?: null;
        if ($url !== null && ! preg_match('#^https?://#i', $url)) {
            $this->error('The webhook URL must start with http:// or https://.');

            return self::FAILURE;
        }

        $secret = 'whsec_' . Str::random(40);
        $app = ClientApp::query()->create([
            'name' => $this->argument('name'),
            'status' => 'active',
            'webhook_url' => $url,
            'webhook_secret' => $secret,
        ]);

        $this->line("App:            {$app->public_id}");
        $this->line("Webhook secret: {$secret}");
        $this->warn('The webhook secret is shown once. Store it now.');

        return self::SUCCESS;
    }
}
