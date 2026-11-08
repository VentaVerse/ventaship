<?php

namespace Tests\Feature\Ui;

use App\Models\Admin;
use App\Models\ClientApp;
use App\Models\CourierAccount;
use App\Models\Shipment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LayoutWidthTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(): void
    {
        $this->actingAs(Admin::query()->create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'correct-horse-battery']));
    }

    private function css(string $file): string
    {
        return (string) file_get_contents(resource_path('css/'.$file));
    }

    public function test_width_tokens_exist(): void
    {
        $tokens = $this->css('blotter.css');
        $this->assertStringContainsString('--bl-content-max: 1240px;', $tokens);
        $this->assertStringContainsString('--bl-form-max: 880px;', $tokens);
    }

    public function test_content_is_capped_and_centered(): void
    {
        $css = $this->css('blotter-components.css');
        $this->assertMatchesRegularExpression('/\.x-content\s*\{[^}]*max-width:\s*var\(--bl-content-max\);[^}]*margin-inline:\s*auto;/s', $css);
        $this->assertMatchesRegularExpression('/\.x-content--form\s*\{[^}]*max-width:\s*var\(--bl-form-max\);/s', $css);
        $this->assertStringNotContainsString('1600px', $css);
    }

    public function test_list_pages_use_the_default_width(): void
    {
        $this->signIn();
        foreach (['/', '/accounts'] as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            $this->assertMatchesRegularExpression('/<main[^>]*class="x-content"/', $html, $url);
        }
    }

    public function test_form_pages_use_the_form_width(): void
    {
        $this->signIn();
        $account = CourierAccount::factory()->create(['courier' => 'quadx', 'status' => 'active']);
        $shipment = Shipment::factory()->create([
            'client_app_id' => ClientApp::factory()->create()->id,
            'courier_account_id' => $account->id,
            'state' => 'draft',
        ]);

        $urls = [
            '/settings',
            route('accounts.show', $account),
            route('shipments.create'),
            route('shipments.show', $shipment),
        ];
        foreach ($urls as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            $this->assertMatchesRegularExpression('/<main[^>]*class="x-content x-content--form"/', $html, $url);
        }
    }
}
