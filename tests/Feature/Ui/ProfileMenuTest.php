<?php

namespace Tests\Feature\Ui;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The admin's menu at the top right of every app page: Account, Settings, the theme switch, Sign out. */
class ProfileMenuTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Admin
    {
        return Admin::query()->create(['name' => 'Rosa dela Cruz', 'email' => 'rosa@example.com', 'password' => 'correct-horse-battery']);
    }

    private function xpath(string $html): \DOMXPath
    {
        $doc = new \DOMDocument;
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?>'.$html);
        libxml_clear_errors();

        return new \DOMXPath($doc);
    }

    public function test_menu_markup_and_aria(): void
    {
        $html = $this->actingAs($this->admin())->get('/')->assertOk()->getContent();
        $x = $this->xpath($html);

        $root = $x->query('//div[@class="x-top__in"]/div[@class="x-prof"]')->item(0);
        $this->assertNotNull($root, 'in the top bar');
        $this->assertSame('profileMenu', $root->getAttribute('x-data'));

        $btn = $x->query('.//button[contains(@class, "x-prof__btn")]', $root)->item(0);
        $this->assertSame('menu', $btn->getAttribute('aria-haspopup'));
        $this->assertSame('false', $btn->getAttribute('aria-expanded'), 'closed on first paint');
        $this->assertSame('x-prof-menu', $btn->getAttribute('aria-controls'));
        $this->assertSame('Rosa dela Cruz', $btn->getAttribute('aria-label'));
        $this->assertSame('RC', trim($x->query('.//*[@class="x-prof__avatar"]', $btn)->item(0)->textContent));
        $this->assertSame('Rosa dela Cruz', trim($x->query('.//*[@class="x-prof__name"]', $btn)->item(0)->textContent));
        $this->assertSame(1, $x->query('.//svg', $btn)->length, 'chevron');

        $menu = $x->query('//*[@id="x-prof-menu"]')->item(0);
        $this->assertSame('menu', $menu->getAttribute('role'));
        $this->assertTrue($menu->hasAttribute('x-cloak'), 'hidden until Alpine runs');
        $this->assertSame('open', $menu->getAttribute('x-show'));

        $items = array_map(fn (\DOMElement $el) => trim($el->textContent), iterator_to_array($x->query('.//*[@role="menuitem" or @role="menuitemradio"]', $menu)));
        $this->assertSame(['Account', 'Settings', 'Light', 'Dark', 'System', 'Sign out'], $items);
        foreach ($x->query('.//*[@role="menuitem" or @role="menuitemradio"]', $menu) as $item) {
            $this->assertSame('-1', $item->getAttribute('tabindex'));
        }

        $this->assertSame(route('account'), $x->query('.//a[normalize-space()="Account"]', $menu)->item(0)->getAttribute('href'));
        $this->assertSame(route('settings.edit'), $x->query('.//a[normalize-space()="Settings"]', $menu)->item(0)->getAttribute('href'));

        // The theme choice is lit by CSS from data-theme-mode; Alpine only keeps aria-checked in step.
        $group = $x->query('.//*[@role="group"][@aria-label="Theme"]', $menu)->item(0);
        $this->assertNotNull($group);
        foreach (['light', 'dark', 'system'] as $mode) {
            $b = $x->query('.//button[@data-mode="'.$mode.'"]', $group)->item(0);
            $this->assertSame('menuitemradio', $b->getAttribute('role'));
            $this->assertSame("setTheme('{$mode}')", $b->getAttribute('x-on:click'));
            $this->assertFalse($b->hasAttribute(':class') || $b->hasAttribute('x-bind:class'), 'no Alpine first-paint state');
        }
        $this->assertSame(1, $x->query('.//*[@role="separator"]', $menu)->length);

        $form = $x->query('.//form[@method="POST"]', $menu)->item(0);
        $this->assertSame(route('logout'), $form->getAttribute('action'));
        $this->assertSame(1, $x->query('.//input[@name="_token"]', $form)->length);
        $this->assertSame('Sign out', trim($x->query('.//button[@type="submit"]', $form)->item(0)->textContent));

        $this->assertStringNotContainsString('style="', $html);
    }

    public function test_menu_is_on_every_app_page_and_page_actions_stay(): void
    {
        $this->actingAs($this->admin());

        foreach (['/', '/addresses', '/accounts', '/developers', '/settings', '/account', route('shipments.create')] as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            $this->assertSame(1, substr_count($html, 'class="x-prof"'), $url);
            $this->assertMatchesRegularExpression('#<div class="x-top">\s*<div class="x-top__in">\s*<button type="button" class="x-menu"#', $html, $url);
        }
        $this->get('/addresses')->assertSee('x-page__actions', false);
    }

    public function test_css_lights_the_theme_from_the_root_attribute_and_sizes_phone_targets(): void
    {
        $css = (string) file_get_contents(resource_path('css/blotter-components.css'));
        foreach (['light', 'dark', 'system'] as $mode) {
            $this->assertStringContainsString(':root[data-theme-mode="'.$mode.'"] .x-prof__mode[data-mode="'.$mode.'"]', $css);
        }
        $this->assertMatchesRegularExpression('/@media \(max-width: 639px\)\s*\{.*?\.x-prof__btn\s*\{[^}]*min-height:\s*44px;[^}]*min-width:\s*44px;.*?\.x-prof__name\s*\{\s*display:\s*none;.*?\.x-prof__item,\s*\.x-prof__mode\s*\{\s*min-height:\s*44px;/s', $css);
        // Its own top bar: full width right of the sidebar (no page-column cap), sticky, profile at the far right.
        $this->assertMatchesRegularExpression('/\.x-top\s*\{[^}]*position:\s*sticky;[^}]*background:\s*var\(--bl-sheet\);[^}]*border-bottom:/s', $css);
        $this->assertDoesNotMatchRegularExpression('/\.x-top__in\s*\{[^}]*max-width/s', $css);
        $this->assertMatchesRegularExpression('/\.x-side__ver\s*\{[^}]*font-size:\s*11\.5px;[^}]*color:\s*var\(--bl-side-group\);/s', $css);
    }

    public function test_initials(): void
    {
        $cases = ['Rosa Admin' => 'RA', 'ana' => 'A', '(Ana) de la Cruz' => 'AC', 'Ñora éclair' => 'ÑÉ', '  ' => 'Z', '' => 'Z'];
        foreach ($cases as $name => $want) {
            $this->assertSame($want, (new Admin(['name' => $name, 'email' => 'zed@example.com']))->initials(), $name);
        }
        $this->assertSame('?', (new Admin(['name' => '--', 'email' => '']))->initials());
    }

    public function test_guest_pages_have_no_menu(): void
    {
        $this->get('/login')->assertOk()->assertDontSee('x-prof', false);
    }
}
