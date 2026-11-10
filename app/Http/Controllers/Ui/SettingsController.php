<?php

namespace App\Http\Controllers\Ui;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ui\SaveGeneralSettingsRequest;
use App\Models\AuditEntry;
use App\Support\SchedulerCron;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class SettingsController extends Controller
{
    private const TABS = ['general' => 'General', 'jobs' => 'Scheduled jobs'];

    private const DEFAULTS = ['shipments.reference_prefix' => 'VS'];

    public function edit(Request $request, Settings $settings): View|RedirectResponse
    {
        $tab = (string) $request->query('tab');
        // The Account tab became its own page.
        if ($tab === 'account') {
            return redirect()->route('account');
        }
        $tab = array_key_exists($tab, self::TABS) ? $tab : 'general';

        return view('settings.edit', [
            'tab' => $tab,
            'tabs' => self::TABS,
            'prefix' => $settings->get('shipments.reference_prefix', 'VS'),
            'cron' => $tab === 'jobs' ? $this->cron($settings) : null,
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

    public function update(SaveGeneralSettingsRequest $request, Settings $settings): RedirectResponse
    {
        $data = $request->validated();
        $new = [
            'shipments.reference_prefix' => $data['shipments_reference_prefix'],
        ];

        DB::transaction(function () use ($settings, $new, $request) {
            foreach ($new as $key => $value) {
                // Unset keys read as their default.
                $from = $settings->get($key, self::DEFAULTS[$key] ?? null);
                if ($from === $value) {
                    continue;
                }
                $settings->set($key, $value);
                AuditEntry::query()->create([
                    'actor_type' => 'admin',
                    'actor_id' => (string) $request->user()->getAuthIdentifier(),
                    'ip' => $request->ip(),
                    'action' => 'setting.changed',
                    'subject_type' => 'setting',
                    'subject_id' => $key,
                    'changes' => ['value' => ['from' => $from, 'to' => $value]],
                    'created_at' => now(),
                ]);
            }
        });

        return redirect()->route('settings.edit', ['tab' => 'general'])->with('status', 'Saved');
    }
}
