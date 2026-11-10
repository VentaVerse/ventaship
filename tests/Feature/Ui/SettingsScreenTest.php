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

    public function test_tabs_render_with_current_values(): void
    {
        $admin = $this->admin();
        app(Settings::class)->set('shipments.reference_prefix', 'ZZ');

        $this->actingAs($admin)->get('/settings')
            ->assertOk()->assertSee('value="ZZ"', false)
            ->assertDontSee('privacy_retention_days', false)->assertDontSee('Keep recipient details');

        $this->actingAs($admin)->get('/settings?tab=bogus')->assertOk()->assertSee('value="ZZ"', false);

        $this->actingAs($admin)->get('/settings?tab=couriers')->assertOk()
            ->assertSee('value="ZZ"', false)
            ->assertDontSee('Couriers &amp; safety', false)->assertDontSee('Allow live')->assertDontSee('Public address');

        // The Account tab is its own page now.
        $this->actingAs($admin)->get('/settings?tab=account')->assertRedirect(route('account'));
    }

    public function test_invalid_prefix_rejected_with_message(): void
    {
        foreach (['ab-1', '', 'ABCDEFGHI', 'ñ1'] as $bad) {
            $this->actingAs($this->admin ??= $this->admin())->from('/settings')
                ->put('/settings', ['tab' => 'general', 'shipments_reference_prefix' => $bad])
                ->assertRedirect('/settings')
                ->assertSessionHasErrors(['shipments_reference_prefix' => 'Use 1–8 letters or digits.']);
        }
        $this->actingAs($this->admin)->from('/settings')
            ->put('/settings', ['tab' => 'general', 'shipments_reference_prefix' => ['VS']])
            ->assertSessionHasErrors('shipments_reference_prefix');

        $this->assertNull(app(Settings::class)->get('shipments.reference_prefix'));
    }

    private ?Admin $admin = null;

    public function test_lowercase_prefix_is_saved_in_capitals(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->put('/settings', ['tab' => 'general', 'shipments_reference_prefix' => ' ab1 '])
            ->assertRedirect('/settings?tab=general')->assertSessionHasNoErrors();
        $this->assertSame('AB1', app(Settings::class)->get('shipments.reference_prefix'));

        $html = $this->actingAs($admin)->get('/settings')->assertOk()->assertSee('value="AB1"', false)->getContent();
        $this->assertMatchesRegularExpression('/<input[^>]*id="f-shipments-reference-prefix"[^>]*>/', $html);
        preg_match('/<input[^>]*id="f-shipments-reference-prefix"[^>]*>/', $html, $m);
        $this->assertStringContainsString('autocapitalize="characters"', $m[0]);
        $this->assertStringContainsString('st-caps', $m[0]);
        $this->assertMatchesRegularExpression('/\.st-caps\s*\{[^}]*text-transform:\s*uppercase;/s', (string) file_get_contents(resource_path('css/pages/settings.css')));
    }

    public function test_only_changed_keys_audited_with_admin_actor(): void
    {
        $admin = $this->admin();
        app(Settings::class)->set('shipments.reference_prefix', 'AB');

        $this->actingAs($admin)->put('/settings', ['tab' => 'general', 'shipments_reference_prefix' => 'CD']);

        $rows = AuditEntry::query()->where('action', 'setting.changed')->get();
        $this->assertCount(1, $rows);
        $row = $rows->first();
        $this->assertSame('shipments.reference_prefix', $row->subject_id);
        $this->assertSame('admin', $row->actor_type);
        $this->assertSame((string) $admin->id, $row->actor_id);
        $this->assertSame(['value' => ['from' => 'AB', 'to' => 'CD']], $row->changes);
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

    /** @return array<string, string> */
    private function general(array $extra = []): array
    {
        return ['tab' => 'general', 'shipments_reference_prefix' => 'VS'] + $extra;
    }

    public function test_general_has_no_sender_section_and_ignores_posted_sender_fields(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->get('/settings')->assertOk()
            ->assertDontSee('Sender')->assertDontSee('pickup_contact_name', false)->assertDontSee('pickup_addr', false);

        $this->actingAs($admin)->put('/settings', $this->general(['pickup_contact_name' => 'Ana Reyes', 'pickup_addr' => ['city' => 'Makati']]))
            ->assertRedirect('/settings?tab=general')->assertSessionHasNoErrors();
        $this->assertSame(0, Setting::query()->where('key', 'like', 'pickup.%')->count());
        $this->assertSame(0, AuditEntry::query()->where('subject_id', 'like', 'pickup.%')->count());
    }
}
