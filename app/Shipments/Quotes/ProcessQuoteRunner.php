<?php

namespace App\Shipments\Quotes;

use Closure;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\PhpExecutableFinder;
use Throwable;

/**
 * Runs each job in its own PHP process (`php artisan ventaship:quote-one`, the job on stdin, the answer on stdout),
 * all started at once. When the budget is spent, every process still running is killed and reported as a timeout:
 * one slow courier never holds the answer. The job carries the customer's details, so it goes on stdin, never on
 * the command line.
 */
final class ProcessQuoteRunner implements QuoteRunner
{
    private const POLL_MICROSECONDS = 15000;

    /** @param  (Closure(string): list<string>)|null  $command  the command for one job key (tests replace it) */
    public function __construct(private readonly ?Closure $command = null) {}

    public function run(array $jobs, float $budgetSeconds): array
    {
        $deadline = microtime(true) + max(0.0, $budgetSeconds);
        $results = [];
        /** @var array<string, InvokedProcess> $running */
        $running = [];

        foreach ($jobs as $key => $job) {
            if (microtime(true) >= $deadline) {
                $results[$key] = ['status' => 'timeout'];

                continue;
            }
            try {
                $running[$key] = Process::path(base_path())
                    ->input((string) json_encode($job))
                    ->timeout(max(1, (int) ceil($budgetSeconds) + 5))
                    ->start($this->command($key));
            } catch (Throwable $e) {
                Log::warning('Quote process could not start', ['exception' => $e::class]);
                $results[$key] = ['status' => 'failed'];
            }
        }

        while ($running !== [] && microtime(true) < $deadline) {
            foreach ($running as $key => $process) {
                if (! $process->running()) {
                    $results[$key] = $this->answer($process);
                    unset($running[$key]);
                }
            }
            if ($running !== []) {
                usleep(self::POLL_MICROSECONDS);
            }
        }

        foreach ($running as $key => $process) {
            if (! $process->running()) {
                $results[$key] = $this->answer($process);

                continue;
            }
            try {
                $process->signal(defined('SIGKILL') ? SIGKILL : 9);
            } catch (Throwable) {
                // Already gone.
            }
            $results[$key] = ['status' => 'timeout'];
        }

        return $results;
    }

    /** @return array{status: 'done', answer: array<string, mixed>}|array{status: 'failed'} */
    private function answer(InvokedProcess $process): array
    {
        try {
            $result = $process->wait();
            $answer = json_decode(trim($result->output()), true);
        } catch (Throwable) {
            return ['status' => 'failed'];
        }

        return $result->successful() && is_array($answer) ? ['status' => 'done', 'answer' => $answer] : ['status' => 'failed'];
    }

    /** @return list<string> */
    private function command(string $key): array
    {
        if ($this->command !== null) {
            return ($this->command)($key);
        }

        $php = (new PhpExecutableFinder)->find(false) ?: 'php';

        return [$php, base_path('artisan'), 'ventaship:quote-one', '--no-ansi', '--no-interaction'];
    }
}
