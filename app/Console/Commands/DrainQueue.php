<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\JobPopped;
use Illuminate\Support\Facades\Date;

/**
 * Empty the queue on a host where the daemon worker cannot run (the same command VentaCart uses).
 *
 * queue:work --stop-when-empty enters Worker::daemon(), which hooks signals through pcntl_signal(). Laravel guards that
 * with extension_loaded('pcntl'), but managed hosts (HestiaCP) load the extension and then list every pcntl function
 * in disable_functions: the guard passes, the call is undefined and the worker dies before a single job runs.
 *
 * Worker::runNextJob(), which queue:work --once uses, touches no signals. So this runs it one job at a time until no
 * job is left to take or the time is up. Tries, backoff and the failed jobs log work as under queue:work; only a job's
 * own timeout cannot be enforced without signals.
 */
class DrainQueue extends Command
{
    protected $signature = 'queue:drain
                            {connection? : The queue connection, or the default}
                            {--queue= : The queues to drain, or the connection default}
                            {--max-time=50 : Seconds to keep taking jobs before stopping}';

    protected $description = 'Process queued jobs until the queue is empty, for hosts where queue:work cannot run';

    private bool $popped = false;

    public function handle(Dispatcher $events): int
    {
        $events->listen(JobPopped::class, function () {
            $this->popped = true;
        });

        $deadline = Date::now()->getPreciseTimestamp(3) + max(0, (int) $this->option('max-time')) * 1000;
        $processed = 0;

        while (Date::now()->getPreciseTimestamp(3) < $deadline) {
            $this->popped = false;

            $this->call('queue:work', array_filter([
                'connection' => $this->argument('connection'),
                '--queue' => $this->option('queue'),
                '--once' => true,
                '--sleep' => 0,
            ], fn ($value) => $value !== null && $value !== ''));

            // Nothing taken: the queue is empty, or holds only jobs not yet due (a retry waiting out its backoff).
            if (! $this->popped) {
                break;
            }
            $processed++;
        }

        $this->info("queue:drain processed {$processed} job(s).");

        return self::SUCCESS;
    }

    /** Whether this PHP can run queue:work as a daemon: the pcntl functions exist, not just the extension. */
    public static function signalsAvailable(): bool
    {
        return function_exists('pcntl_signal') && function_exists('pcntl_async_signals');
    }
}
