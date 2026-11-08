<?php

namespace Tests\Unit\Webhooks;

use App\Webhooks\Outbound\RetrySchedule;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class RetryScheduleTest extends TestCase
{
    public function test_schedule_and_give_up_at_72h(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-08 10:00:00', 'Asia/Manila'));
        $start = now()->subMinute();

        $expected = [1 => 60, 2 => 300, 3 => 900, 4 => 1800, 5 => 3600, 6 => 7200, 7 => 14400, 8 => 21600, 9 => 21600, 30 => 21600];
        foreach ($expected as $attempts => $seconds) {
            $this->assertSame($seconds, RetrySchedule::delayAfter($attempts, $start), "after {$attempts} attempts");
        }

        $this->assertSame(60, RetrySchedule::delayAfter(0, $start));
        $this->assertSame(21600, RetrySchedule::delayAfter(12, now()->subHours(72)->addSecond()));
        $this->assertNull(RetrySchedule::delayAfter(12, now()->subHours(72)));
        $this->assertNull(RetrySchedule::delayAfter(1, now()->subHours(80)));
    }
}
