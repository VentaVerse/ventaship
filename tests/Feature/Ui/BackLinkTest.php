<?php

namespace Tests\Feature\Ui;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Every second-level page has a back link to the page above it; first-level pages have none. */
class BackLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_second_level_pages_have_a_back_link(): void
    {
        $this->actingAs(Admin::query()->create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'correct-horse-battery']));

        $pages = [
            route('accounts.show', 'spx') => [route('accounts.index'), 'Courier accounts'],
            route('addresses.pickup.create') => [route('addresses.index', ['tab' => 'pickup']), 'Addresses'],
            route('addresses.customers.create') => [route('addresses.index', ['tab' => 'customers']), 'Addresses'],
        ];
        foreach ($pages as $url => [$back, $label]) {
            $html = $this->get($url)->assertOk()->getContent();
            $this->assertMatchesRegularExpression('#<a class="x-back" href="'.preg_quote(e($back), '#').'"><svg[^>]*>.*?</svg>\s*'.$label.'</a>#s', $html, $url);
        }

        foreach ([route('fulfillment'), route('accounts.index'), route('addresses.index'), route('shipments.create')] as $url) {
            $this->assertStringNotContainsString('class="x-back"', $this->get($url)->assertOk()->getContent(), $url);
        }
    }
}
