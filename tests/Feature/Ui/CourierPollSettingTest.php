<?php

namespace Tests\Feature\Ui;

use App\Models\Admin;
use App\Support\CourierPoll;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Settings → Scheduled jobs → Courier status check: how often it runs, and Run now. */
class CourierPollSettingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(Admin::query()->create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'correct-horse-battery']));
    }

    public function test_section_shows_the_interval_and_last_check(): void
    {
        $this->get(route('settings.edit'))->assertOk()
            ->assertSee('Courier status check')->assertSee('value="10"', false)->assertSee('Not yet')
            ->assertSee('Run now')->assertSee('action="'.route('settings.poll.run').'"', false);

        app(Settings::class)->set(CourierPoll::LAST_KEY, ['at' => '2026-11-15T15:10:04+00:00', 'count' => 3]);
        $this->get(route('settings.edit'))->assertSee('15 Nov, 11:10 PM · 3 shipments');
    }

    public function test_save_sets_the_interval(): void
    {
        $this->put(route('settings.poll.update'), ['poll_minutes' => '3'])
            ->assertRedirect(route('settings.edit'))->assertSessionHas('status', 'Saved');
        $this->assertSame(3, CourierPoll::minutes(app(Settings::class)));
        $this->get(route('settings.edit'))->assertSee('value="3"', false);
    }

    public function test_save_refuses_outside_1_to_59(): void
    {
        foreach (['0', '60', 'abc', ''] as $bad) {
            $this->put(route('settings.poll.update'), ['poll_minutes' => $bad])->assertSessionHasErrors(['poll_minutes' => 'Enter 1 to 59.']);
        }
        $this->assertSame(10, CourierPoll::minutes(app(Settings::class)));
    }

    public function test_run_now_checks_and_records(): void
    {
        // No open shipments: the real check runs and asks no courier.
        $this->post(route('settings.poll.run'))->assertRedirect(route('settings.edit'))->assertSessionHas('status', 'Checked 0 shipments');
        $last = CourierPoll::last(app(Settings::class));
        $this->assertSame(0, $last['count']);
        $this->assertTrue($last['at']->isToday());
    }

    public function test_needs_sign_in(): void
    {
        auth()->logout();
        $this->put(route('settings.poll.update'), ['poll_minutes' => '3'])->assertRedirect('/login');
        $this->post(route('settings.poll.run'))->assertRedirect('/login');
    }
}
