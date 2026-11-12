<?php

namespace Tests\Feature\Ui;

use App\Console\Commands\ScheduleHeartbeat;
use App\Models\Admin;
use App\Models\AuditEntry;
use App\Models\Setting;
use App\Support\SchedulerCron;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Js;
use Tests\TestCase;

class SettingsScreenTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Admin
    {
        return Admin::query()->create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'correct-horse-battery']);
    }

    public function test_settings_shows_scheduled_jobs_only_and_no_reference_prefix(): void
    {
        $admin = $this->admin();
        app(Settings::class)->set('shipments.reference_prefix', 'ZZ');

        foreach (['/settings', '/settings?tab=general', '/settings?tab=bogus'] as $url) {
            $this->actingAs($admin)->get($url)->assertOk()
                ->assertSee('Scheduled jobs')->assertSee('Cron line')
                ->assertDontSee('Reference prefix')->assertDontSee('shipments_reference_prefix', false)->assertDontSee('value="ZZ"', false);
        }

        // Nothing is saved from this page any more.
        $this->actingAs($admin)->put('/settings', ['shipments_reference_prefix' => 'AB'])->assertStatus(405);
        $this->assertSame('ZZ', app(Settings::class)->get('shipments.reference_prefix'));
        $this->assertSame(0, AuditEntry::query()->count());

        // The Account tab is its own page now.
        $this->actingAs($admin)->get('/settings?tab=account')->assertRedirect(route('account'));
    }

    public function test_jobs_tab_shows_the_whole_cron_line_and_last_run_tone(): void
    {
        $admin = $this->admin();
        $line = SchedulerCron::line();

        $html = $this->actingAs($admin)->get('/settings?tab=jobs')->assertOk()->getContent();
        $x = $this->xpath($html);
        $box = $x->query('//textarea[@id="f-cron-line"]')->item(0);
        $this->assertNotNull($box, 'a wrapping block, not a one-line input');
        $this->assertSame($line, $box->textContent, 'the whole line');
        $this->assertTrue($box->hasAttribute('readonly'));
        $this->assertSame(0, $x->query('//input[@id="f-cron-line"]')->length);
        $this->assertSame('Cron line', trim($x->query('//label[@for="f-cron-line"]')->item(0)?->textContent ?? ''));
        // Copy copies exactly the line.
        $data = $x->query('//textarea[@id="f-cron-line"]/ancestor::*[@x-data][1]')->item(0)->getAttribute('x-data');
        $config = ['url' => null, 'value' => $line, 'mask' => $line, 'isSet' => true, 'name' => null];
        $this->assertSame('credentialField('.Js::from($config).')', $data);
        // One heading only: the tab's name; no repeated "Scheduler" heading, no unused live region.
        $this->assertStringNotContainsString('Scheduler</h2>', $html);
        $this->assertStringNotContainsString('credential-status', $html);
        $css = (string) file_get_contents(resource_path('css/blotter-components.css'));
        $this->assertMatchesRegularExpression('/\.x-copyvalue__text\s*\{[^}]*white-space:\s*pre-wrap;[^}]*overflow-wrap:\s*anywhere;/s', $css);

        $this->actingAs($admin)->get('/settings?tab=jobs')->assertSeeInOrder(['Last run', 'x-badge--mute', 'Never']);
        app(Settings::class)->set(ScheduleHeartbeat::KEY, now()->subSeconds(90)->toIso8601String());
        $this->actingAs($admin)->get('/settings?tab=jobs')->assertSeeInOrder(['Last run', 'x-badge--good', '1 min ago']);
        app(Settings::class)->set(ScheduleHeartbeat::KEY, now()->subMinutes(4)->toIso8601String());
        $this->actingAs($admin)->get('/settings?tab=jobs')->assertSeeInOrder(['Last run', 'x-badge--attn', '4 min ago']);
    }

    public function test_every_control_is_44px_on_phones(): void
    {
        $css = (string) file_get_contents(resource_path('css/pages/settings.css'));
        $this->assertMatchesRegularExpression('/@media \(max-width: 639px\)\s*\{\s*\.st-panel \.x-input:not\(textarea\),\s*\.st-panel \.x-btn\s*\{\s*min-height:\s*44px;/s', $css);
        foreach (['.st-switch', '.st-copy--short', '.st-jobs'] as $dead) {
            $this->assertStringNotContainsString($dead, $css);
        }
        // The address forms still rely on these.
        $this->assertStringContainsString('.st-row .x-phone', $css);
        $this->assertStringContainsString('.st-row .ph-addr', $css);
    }

    private function xpath(string $html): \DOMXPath
    {
        $doc = new \DOMDocument;
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?>'.$html);
        libxml_clear_errors();

        return new \DOMXPath($doc);
    }
}
