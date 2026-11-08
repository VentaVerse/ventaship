<?php

namespace Tests\Feature\Console;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class ScheduleTest extends TestCase
{
    public function test_expected_commands_scheduled(): void
    {
        $expected = [
            'ventaship:couriers-poll' => ['*/10 * * * *', true],
            'ventaship:shipments-sweep' => ['* * * * *', true],
            'ventaship:events-deliver' => ['* * * * *', true],
            'ventaship:receipts-reprocess' => ['* * * * *', true],
            'ventaship:logs-prune --signatures-only' => ['0 * * * *', true],
            'ventaship:logs-prune' => ['0 0 * * *', true],
            'ventaship:pii-purge' => ['0 0 * * *', true],
        ];

        foreach ($expected as $command => [$expression, $withoutOverlapping]) {
            $event = $this->event($command);
            $this->assertNotNull($event, "{$command} is scheduled");
            $this->assertSame($expression, $event->expression, "{$command} runs at {$expression}");
            $this->assertSame($withoutOverlapping, $event->withoutOverlapping, "{$command} never overlaps itself");
        }
    }

    public function test_poll_interval_follows_couriers_poll_minutes(): void
    {
        $this->assertSame(10, (int) config('couriers.poll_minutes'));
    }

    /** The scheduled event whose command ends with exactly these arguments. */
    private function event(string $command): ?Event
    {
        return collect(app(Schedule::class)->events())
            ->first(fn (Event $e) => preg_match('/\s'.preg_quote($command, '/').'$/', (string) $e->command) === 1
                || str_ends_with((string) $e->command, "'artisan' {$command}"));
    }
}
