<?php

namespace Tests\Feature\Ui;

use App\Models\Admin;
use App\Models\ClientApp;
use App\Models\CourierAccount;
use App\Platforms\PlatformApps;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Every Copy button carries Copied / Couldn't copy labels in one cell and its own polite live region. */
class CopyFeedbackTest extends TestCase
{
    use RefreshDatabase;

    private function assertCopyFeedback(string $html, string $where, int $atLeast = 1): void
    {
        $buttons = preg_match_all('/<button[^>]*class="[^"]*x-copy[^"]*"[^>]*data-copy="idle"[^>]*x-bind:data-copy="copied"/', $html);
        $this->assertGreaterThanOrEqual($atLeast, $buttons, $where);
        $this->assertSame($buttons, substr_count($html, 'aria-live="polite" x-text="copyNote"'), $where.': one live region per Copy button');
        $this->assertSame($buttons, substr_count($html, 'x-copy__label--ok'), $where);
        $this->assertStringContainsString('Copied</span>', $html, $where);
        $this->assertStringContainsString("Couldn't copy</span>", $html, $where);
        $this->assertDoesNotMatchRegularExpression('/x-on:click="copy\(\)"[^>]*>\s*Copy\s*<\/button>/', $html, $where.': no plain Copy button left');
    }

    public function test_every_copy_button_has_feedback_and_a_live_region(): void
    {
        $this->actingAs(Admin::query()->create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'correct-horse-battery']));
        $app = ClientApp::factory()->create(['name' => 'Shop', 'webhook_url' => 'https://a.test/h']);
        app(PlatformApps::class)->issueKey($app, 'Shop');

        $this->assertCopyFeedback($this->get(route('developers.index', ['tab' => 'keys']))->assertOk()->getContent(), 'API keys');
        $this->assertCopyFeedback($this->get(route('developers.index', ['tab' => 'webhooks']))->assertOk()->getContent(), 'Webhooks');
        $this->assertCopyFeedback($this->get('/settings?tab=jobs')->assertOk()->getContent(), 'Settings cron line');

        CourierAccount::factory()->create(['courier' => 'spx', 'mode' => 'sandbox', 'webhook_token' => str_repeat('t', 40)]);
        $this->assertCopyFeedback($this->get(route('accounts.show', 'spx'))->assertOk()->getContent(), 'SPX account');
    }

    public function test_the_copy_feedback_css_keeps_one_width(): void
    {
        $css = (string) file_get_contents(resource_path('css/blotter-components.css'));
        $this->assertMatchesRegularExpression('/\.x-copy__label\s*\{[^}]*grid-area:\s*1 \/ 1;/s', $css);
        $this->assertStringContainsString('visibility: hidden', $css);
    }
}
