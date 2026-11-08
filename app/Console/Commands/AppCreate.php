<?php

namespace App\Console\Commands;

use App\Platforms\PlatformAppException;
use App\Platforms\PlatformApps;
use Illuminate\Console\Command;

class AppCreate extends Command
{
    protected $signature = 'ventaship:app-create {name} {--webhook-url=}';

    protected $description = 'Create a client app and print its webhook secret';

    public function handle(PlatformApps $apps): int
    {
        try {
            $app = $apps->create((string) $this->argument('name'), $this->option('webhook-url') ?: null);
        } catch (PlatformAppException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $secret = $app->webhook_secret;

        $this->line("App:            {$app->public_id}");
        $this->line("Webhook secret: {$secret}");
        $this->warn('Store the webhook secret in the platform.');

        return self::SUCCESS;
    }
}
