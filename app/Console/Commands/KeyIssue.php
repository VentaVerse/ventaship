<?php

namespace App\Console\Commands;

use App\Models\ClientApp;
use App\Platforms\PlatformAppException;
use App\Platforms\PlatformApps;
use Illuminate\Console\Command;

class KeyIssue extends Command
{
    protected $signature = 'ventaship:key-issue {app} {--name=}';

    protected $description = 'Issue an API key and signing secret for a client app (shown once)';

    public function handle(PlatformApps $apps): int
    {
        $app = ClientApp::query()->where('public_id', $this->argument('app'))->first();
        if ($app === null) {
            $this->error('No such app.');

            return self::FAILURE;
        }

        try {
            $issued = $apps->issueKey($app, (string) ($this->option('name') ?: 'Default'));
        } catch (PlatformAppException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $key = $issued['plaintext_key'];
        $secret = $issued['secret'];

        $this->line("API key:        {$key}");
        $this->line("Signing secret: {$secret}");
        $this->warn('These are shown once. Store them now.');

        return self::SUCCESS;
    }
}
