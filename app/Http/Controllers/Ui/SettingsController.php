<?php

namespace App\Http\Controllers\Ui;

use App\Http\Controllers\Controller;
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
        ]);
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
