<?php

namespace Tests\Feature\Ui;

use App\Models\Admin;
use App\Support\Navigation;
use Illuminate\Support\Facades\Route;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NavigationTest extends TestCase
{
    use RefreshDatabase;

    private function registerRoute(string $name, ?\Closure $action = null): void
    {
        Route::get('/_nav/'.$name, $action ?? fn () => 'ok')->name($name);
        Route::getRoutes()->refreshNameLookups();
    }

    /** @return array<int, string> */
    private function routes(): array
    {
        return collect(Navigation::groups())
            ->flatMap(fn (array $group) => $group['items'])
            ->pluck('route')
            ->all();
    }

    /** @return array<int, ?string> */
    private function labels(): array
    {
        return array_column(Navigation::groups(), 'label');
    }

    public function test_groups_only_contain_existing_routes(): void
    {
        $this->registerRoute('fulfillment');

        $routes = $this->routes();

        $this->assertSame(['fulfillment', 'settings.edit'], $routes);
        $this->assertNotContains('accounts.index', $routes);
    }

    public function test_empty_groups_are_dropped(): void
    {
        $this->registerRoute('fulfillment');
        $this->registerRoute('settings.edit');

        $labels = $this->labels();

        $this->assertSame([null, null], $labels);
    }

    public function test_group_order_and_labels_follow_the_plan(): void
    {
        foreach (['fulfillment', 'accounts.index', 'apps.index', 'system.health', 'settings.edit'] as $name) {
            $this->registerRoute($name);
        }

        $this->assertSame([null, 'Setup', 'Developers', 'System', null], $this->labels());
    }

    public function test_layout_renders_without_an_admin(): void
    {
        $this->registerRoute('fulfillment', fn () => view('layouts.app'));

        $this->get('/_nav/fulfillment')
            ->assertOk()
            ->assertSee('Ventaship')
            ->assertSee('Shipments');
    }

    public function test_only_the_current_system_item_is_active(): void
    {
        foreach (['system.health', 'system.unmatched'] as $name) {
            $this->registerRoute($name);
        }
        $this->registerRoute('system.failed', fn () => view('layouts.app'));

        $html = $this->get('/_nav/system.failed')->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'aria-current="page"'));
        $this->assertMatchesRegularExpression('/aria-current="page"[^>]*>\s*<svg.*?<span>Failed jobs<\/span>/s', $html);
    }

    public function test_footer_shows_admin_name_and_sign_out(): void
    {
        $this->registerRoute('fulfillment', fn () => view('layouts.app'));
        $admin = Admin::create(['name' => 'Rosa Admin', 'email' => 'rosa@example.test', 'password' => 'secret-pass']);

        $this->actingAs($admin)->get('/_nav/fulfillment')
            ->assertOk()
            ->assertSee('Rosa Admin')
            ->assertSee('Sign out')
            ->assertSee('action="'.route('logout').'"', false);
    }
}
