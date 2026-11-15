<?php

namespace Tests\Feature\Console;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScheduleTest extends TestCase
{
    use RefreshDatabase;

    public function test_expected_commands_scheduled(): void
    {
        $expected = [
            'ventaship:couriers-poll' => ['*/10 * * * *', true],
            'ventaship:shipments-sweep' => ['* * * * *', true],
            'ventaship:events-deliver' => ['* * * * *', true],
            'ventaship:receipts-reprocess' => ['* * * * *', true],
            'ventaship:logs-prune' => ['0 0 * * *', true],
        ];

        foreach ($expected as $command => [$expression, $withoutOverlapping]) {
            $event = $this->event($command);
            $this->assertNotNull($event, "{$command} is scheduled");
            $this->assertSame($expression, $event->expression, "{$command} runs at {$expression}");
            $this->assertSame($withoutOverlapping, $event->withoutOverlapping, "{$command} never overlaps itself");
        }
    }

    public function test_pii_purge_is_no_longer_scheduled_or_registered(): void
    {
        $this->assertNull($this->event('ventaship:pii-purge'));
        $this->assertArrayNotHasKey('ventaship:pii-purge', \Illuminate\Support\Facades\Artisan::all());
    }

    public function test_poll_interval_comes_from_settings_not_env(): void
    {
        $this->assertNull(config('couriers.poll_minutes'), 'no .env knob');
        $settings = app(\App\Support\Settings::class);
        $this->assertSame(10, \App\Support\CourierPoll::minutes($settings));
        $settings->set(\App\Support\CourierPoll::MINUTES_KEY, 3);
        $this->assertSame(3, \App\Support\CourierPoll::minutes($settings));
        $settings->set(\App\Support\CourierPoll::MINUTES_KEY, 90);
        $this->assertSame(10, \App\Support\CourierPoll::minutes($settings), 'out of range falls back');
    }

    /** The scheduled event whose command ends with exactly these arguments. */
    private function event(string $command): ?Event
    {
        return collect(app(Schedule::class)->events())
            ->first(fn (Event $e) => preg_match('/\s'.preg_quote($command, '/').'$/', (string) $e->command) === 1
                || str_ends_with((string) $e->command, "'artisan' {$command}"));
    }
}
