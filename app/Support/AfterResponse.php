<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Work run in this PHP process once the response is sent, so an install with no queue worker still gets it
 * done and a caller (a courier webhook) is answered first.
 *
 * - HTTP: on the app's terminating step, after the response went out (php-fpm's fastcgi_finish_request).
 * - Queue worker: after each job (JobAttempted, see AppServiceProvider), so a long-lived worker does not hoard it.
 * - Artisan: when the command ends.
 *
 * Work queued while flushing (a receipt that records a status that has an event to deliver) runs in the same flush.
 * A failure is logged and swallowed: each piece of work has its own row the scheduled commands pick up again.
 */
final class AfterResponse
{
    /** @var list<array{0: string, 1: Closure}> */
    private array $pending = [];

    private bool $hooked = false;

    private bool $flushing = false;

    public static function run(string $what, Closure $work): void
    {
        app(self::class)->push($what, $work);
    }

    public function push(string $what, Closure $work): void
    {
        $this->pending[] = [$what, $work];

        if (! $this->hooked) {
            $this->hooked = true;
            app()->terminating(fn () => $this->flush());
        }
    }

    public function flush(): void
    {
        if ($this->flushing) {
            return; // the outer flush picks up whatever was just added
        }

        $this->flushing = true;
        try {
            while (($next = array_shift($this->pending)) !== null) {
                [$what, $work] = $next;
                try {
                    $work();
                } catch (Throwable $e) {
                    // The class only: a message can carry a URL with a token in its query.
                    Log::error('After-response work failed', ['work' => $what, 'exception' => $e::class]);
                }
            }
        } finally {
            $this->flushing = false;
        }
    }

    public function pending(): int
    {
        return count($this->pending);
    }
}
