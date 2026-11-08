<?php

namespace App\Console\Commands;

use App\Models\ClientApp;
use App\Webhooks\Outbound\Jobs\DeliverEvent;
use App\Webhooks\Outbound\Outbox;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Records a `ping` event for a client app and queues it for delivery at once. */
class AppPing extends Command
{
    protected $signature = 'ventaship:app-ping {app : The client app id (app_…)}';

    protected $description = "Send a test ping to a client app's webhook URL";

    public function handle(): int
    {
        $app = ClientApp::query()->where('public_id', (string) $this->argument('app'))->first();
        if ($app === null) {
            $this->error('No such app.');

            return self::FAILURE;
        }

        if (blank($app->webhook_url)) {
            $this->warn("{$app->public_id} has no webhook URL; the ping will be skipped.");
        }

        $e = DB::transaction(fn () => Outbox::record($app, 'ping', ['message' => 'ping']));
        DeliverEvent::dispatch($e->id);

        $this->info("Ping {$e->public_id} queued.");

        return self::SUCCESS;
    }
}
