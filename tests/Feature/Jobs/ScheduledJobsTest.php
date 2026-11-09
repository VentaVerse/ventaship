<?php

namespace Tests\Feature\Jobs;

use App\Console\Commands\ScheduleHeartbeat;
use App\Models\Admin;
use App\Support\SchedulerCron;
use App\Support\Settings;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScheduledJobsTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<string> */
    private function scheduled(): array
    {
        return array_map(fn (Event $e) => (string) $e->command, app(Schedule::class)->events());
    }

    private function find(string $needle): Event
    {
        foreach (app(Schedule::class)->events() as $e) {
            if (str_contains((string) $e->command, $needle)) {
                return $e;
            }
        }
        $this->fail("{$needle} is not scheduled");
    }

    public function test_schedule_works_the_queue_and_beats_every_minute_and_keeps_the_rest(): void
    {
        $worker = $this->find('queue:work');
        $this->assertStringContainsString('--stop-when-empty', $worker->command);
        $this->assertStringContainsString('--max-time=50', $worker->command);
        $this->assertStringContainsString('--queue=couriers,inbound,outgoing,default', $worker->command);
        $this->assertSame('* * * * *', $worker->expression);
        $this->assertTrue($worker->withoutOverlapping);
        $this->assertTrue($worker->runInBackground);

        $this->assertSame('* * * * *', $this->find('ventaship:schedule-heartbeat')->expression);

        foreach (['couriers-poll', 'shipments-sweep', 'events-deliver', 'receipts-reprocess', 'logs-prune', 'pii-purge'] as $name) {
            $this->find("ventaship:{$name}");
        }
        $this->assertCount(8, $this->scheduled());
    }

    public function test_heartbeat_stores_now(): void
    {
        $this->freezeSecond();

        $this->artisan('ventaship:schedule-heartbeat')->assertExitCode(0);

        $this->assertSame(now()->toIso8601String(), (new Settings)->get(ScheduleHeartbeat::KEY));
        $this->assertTrue(SchedulerCron::lastRun(new Settings)->equalTo(now()));
    }

    public function test_php_binary_falls_back_from_fpm(): void
    {
        $this->assertSame('/usr/bin/php8.5', SchedulerCron::phpBinary('/usr/bin/php8.5'));
        foreach (['/usr/sbin/php-fpm8.5', '/usr/sbin/php-fpm', ''] as $fpm) {
            $binary = SchedulerCron::phpBinary($fpm);
            $this->assertStringNotContainsString('fpm', $binary);
            $this->assertMatchesRegularExpression('~^(php|/.+/php\d+\.\d+)$~', $binary);
        }
    }

    public function test_settings_shows_the_cron_command_with_copy_buttons(): void
    {
        $html = $this->admin()->get('/settings?tab=jobs')->assertOk()
            ->assertSee('Scheduled jobs')
            ->assertSee('* * * * * cd '.base_path().' && '.PHP_BINARY.' artisan schedule:run >> /dev/null 2>&1')
            ->assertSee('value="cd '.e(base_path()).' &amp;&amp; '.e(PHP_BINARY).' artisan schedule:run &gt;&gt; /dev/null 2&gt;&amp;1"', false)
            ->getContent();

        foreach (['Minute', 'Hour', 'Day', 'Month', 'Weekday'] as $field) {
            $id = 'f-cron-'.strtolower($field);
            $this->assertMatchesRegularExpression('~<label class="fm-label"\s+for="'.$id.'"\s*>\s*'.$field.'\s*</label>~', $html);
            $this->assertMatchesRegularExpression('~id="'.$id.'"[^>]*value="\*"~', $html);
        }
        $this->assertSame(7, substr_count($html, 'x-on:click="copy()"'), 'cron line, five fields, command');
        $this->assertStringContainsString('aria-label="Copy Command"', $html);
        $this->assertStringNotContainsString('<style', $html);
        $this->assertStringNotContainsString('style="', $html);
    }

    public function test_last_run_states(): void
    {
        $this->admin();

        $this->get('/settings?tab=jobs')->assertSeeInOrder(['Last run', 'x-badge--attn', 'Never']);

        app(Settings::class)->set(ScheduleHeartbeat::KEY, now()->subMinutes(2)->subSeconds(5)->toIso8601String());
        $this->get('/settings?tab=jobs')->assertSeeInOrder(['Last run', 'x-badge--good', '2 min ago']);

        app(Settings::class)->set(ScheduleHeartbeat::KEY, now()->subMinutes(5)->toIso8601String());
        $this->get('/settings?tab=jobs')->assertSeeInOrder(['Last run', 'x-badge--attn', '5 min ago']);

        app(Settings::class)->set(ScheduleHeartbeat::KEY, now()->subSeconds(20)->toIso8601String());
        $this->get('/settings?tab=jobs')->assertSeeInOrder(['Last run', 'x-badge--good', 'Just now']);
    }

    private function admin(): static
    {
        return $this->actingAs(Admin::query()->create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'correct-horse-battery']));
    }
}
