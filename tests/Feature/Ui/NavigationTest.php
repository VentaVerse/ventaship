<?php

namespace Tests\Feature\Ui;

use App\Support\Navigation;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class NavigationTest extends TestCase
{
    /** @return array<int, string> */
    private function routes(): array
    {
        Route::getRoutes()->refreshNameLookups();

        return collect(Navigation::groups())
            ->flatMap(fn (array $group) => $group['items'])
            ->pluck('route')
            ->all();
    }

    /** @return array<int, ?string> */
    private function labels(): array
    {
        Route::getRoutes()->refreshNameLookups();

        return array_column(Navigation::groups(), 'label');
    }

    public function test_groups_only_contain_existing_routes(): void
    {
        Route::get('/_nav/fulfillment', fn () => 'ok')->name('fulfillment');

        $routes = $this->routes();

        $this->assertSame(['fulfillment'], $routes);
        $this->assertNotContains('accounts.index', $routes);
    }

    public function test_empty_groups_are_dropped(): void
    {
        Route::get('/_nav/fulfillment', fn () => 'ok')->name('fulfillment');
        Route::get('/_nav/settings', fn () => 'ok')->name('settings.edit');

        $labels = $this->labels();

        $this->assertSame([null, null], $labels);
    }

    public function test_group_order_and_labels_follow_the_plan(): void
    {
        foreach (['fulfillment', 'accounts.index', 'apps.index', 'system.health', 'settings.edit'] as $name) {
            Route::get('/_nav/'.$name, fn () => 'ok')->name($name);
        }

        $this->assertSame([null, 'Setup', 'Developers', 'System', null], $this->labels());
    }

    public function test_layout_renders_without_an_admin_and_without_logout_route(): void
    {
        Route::get('/_nav/fulfillment', fn () => view('layouts.app'))->name('fulfillment');
        Route::getRoutes()->refreshNameLookups();

        $this->get('/_nav/fulfillment')
            ->assertOk()
            ->assertSee('Ventaship')
            ->assertSee('Fulfillment')
            ->assertDontSee('Sign out');
    }
}
