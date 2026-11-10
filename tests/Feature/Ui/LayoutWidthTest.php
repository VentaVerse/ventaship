<?php

namespace Tests\Feature\Ui;

use App\Models\Admin;
use App\Models\ClientApp;
use App\Models\CourierAccount;
use App\Models\Shipment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Every app page sits in one centered content column: same left edge and width for title, tabs and cards. */
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

    public function test_one_page_width_token(): void
    {
        $tokens = $this->css('blotter.css');
        $this->assertStringContainsString('--bl-page-max: 1060px;', $tokens);
        foreach (['--bl-content-max', '--bl-form-max', '--bl-wide-max'] as $gone) {
            $this->assertStringNotContainsString($gone, $tokens);
        }
    }

    public function test_content_column_is_capped_and_centered_with_no_variants(): void
    {
        $css = $this->css('blotter-components.css');
        $this->assertMatchesRegularExpression('/\.x-content\s*\{[^}]*max-width:\s*calc\(var\(--bl-page-max\) \+ 2 \* var\(--bl-gutter\)\);[^}]*margin-inline:\s*auto;/s', $css);
        $this->assertStringNotContainsString('x-content--', $css);

        // No page-level panel narrows the column.
        $this->assertStringNotContainsString('.ac-list', $this->css('pages/accounts.css'));
        $this->assertStringNotContainsString('.ac-form', $this->css('pages/accounts.css'));
    }

    public function test_every_page_renders_the_single_page_width_class(): void
    {
        $this->signIn();
        $account = CourierAccount::factory()->create(['courier' => 'quadx', 'status' => 'active']);
        $shipment = Shipment::factory()->create([
            'client_app_id' => ClientApp::factory()->create()->id,
            'courier_account_id' => $account->id,
            'state' => 'draft',
        ]);

        $urls = [
            '/',
            '/addresses',
            route('addresses.pickup.create'),
            route('addresses.customers.create'),
            '/accounts',
            route('accounts.show', $account->courier),
            '/developers',
            '/settings',
            '/settings?tab=jobs',
            '/account',
            route('shipments.create'),
            route('shipments.show', $shipment),
        ];
        foreach ($urls as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            $this->assertMatchesRegularExpression('/<main id="main" tabindex="-1" class="x-content">/', $html, $url);
            $this->assertStringNotContainsString('x-content--', $html, $url);
        }
    }
}
