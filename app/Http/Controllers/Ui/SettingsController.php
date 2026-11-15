<?php

namespace App\Http\Controllers\Ui;

use App\Http\Controllers\Controller;
use App\Shipments\Poller;
use App\Support\CourierPoll;
use App\Support\SchedulerCron;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SettingsController extends Controller
{
    private const TABS = ['jobs' => 'Scheduled jobs'];

    public function edit(Request $request, Settings $settings): View|RedirectResponse
    {
        // The Account tab became its own page.
        if ((string) $request->query('tab') === 'account') {
            return redirect()->route('account');
        }

        return view('settings.edit', [
            'tab' => 'jobs',
            'tabs' => self::TABS,
            'cron' => $this->cron($settings),
            'pollMinutes' => CourierPoll::minutes($settings),
            'pollLast' => CourierPoll::last($settings),
        ]);
    }

    public function updatePoll(Request $request, Settings $settings): RedirectResponse
    {
        $data = $request->validate(
            ['poll_minutes' => ['required', 'integer', 'min:1', 'max:59']],
            ['poll_minutes.*' => 'Enter 1 to 59.'],
        );
        $settings->set(CourierPoll::MINUTES_KEY, (int) $data['poll_minutes']);

        return redirect()->route('settings.edit')->with('status', 'Saved');
    }

    /** Checks every open shipment with its courier now, as the scheduled run does. */
    public function runPoll(Poller $poller, Settings $settings): RedirectResponse
    {
        $count = CourierPoll::run($poller, $settings);

        return redirect()->route('settings.edit')->with('status', 'Checked '.$count.' '.($count === 1 ? 'shipment' : 'shipments'));
    }

    /** @return array{line: string, lastRun: array{0: string, 1: string}} */
    private function cron(Settings $settings): array
    {
        return [
            'line' => SchedulerCron::line(),
            'lastRun' => SchedulerCron::lastRunBadge(SchedulerCron::lastRun($settings)),
        ];
    }
}
