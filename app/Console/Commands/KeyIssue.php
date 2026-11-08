<?php

namespace App\Console\Commands;

use App\Models\ClientApp;
use App\Platforms\PlatformAppException;
use App\Platforms\PlatformApps;
use Illuminate\Console\Command;

class KeyIssue extends Command
{
    protected $signature = 'ventaship:key-issue {app} {--name=}';

    protected $description = 'Issue an API key for a client app and print it';

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

        $this->line($issued['plaintext_key']);

        return self::SUCCESS;
    }
}
