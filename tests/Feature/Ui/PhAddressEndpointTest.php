<?php

namespace Tests\Feature\Ui;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

class PhAddressEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Admin
    {
        return Admin::query()->create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'correct-horse-battery']);
    }

    public function test_endpoint_lists_children_and_requires_auth(): void
    {
        $this->get('/ph/provinces')->assertRedirect('/login');

        $admin = $this->admin();
        $this->actingAs($admin)->getJson('/ph/provinces')->assertOk()->assertJsonCount(82)
            ->assertJsonFragment(['code' => '130000000', 'name' => 'Metro Manila']);

        $cities = $this->actingAs($admin)->getJson('/ph/cities?parent=130000000')->assertOk()
            ->assertJsonFragment(['code' => '133900000', 'name' => 'Manila', 'zip' => '1006'])->json();
        $this->assertSame(['code', 'name', 'zip'], array_keys($cities[0]));

        $districts = $this->actingAs($admin)->getJson('/ph/districts?parent=133900000')->assertOk()->assertJsonCount(14)->json();
        $this->actingAs($admin)->getJson('/ph/barangays?parent='.$districts[0]['code'])->assertOk();
        $this->actingAs($admin)->getJson('/ph/barangays?parent=bogus')->assertOk()->assertExactJson([]);
        $this->actingAs($admin)->getJson('/ph/cities')->assertOk()->assertExactJson([]);
        $this->actingAs($admin)->getJson('/ph/cities?parent[]=130000000')->assertOk()->assertExactJson([]);
        $this->actingAs($admin)->getJson('/ph/regions')->assertNotFound();
    }

    public function test_component_renders_selects_and_hidden_fields(): void
    {
        view()->share('errors', new ViewErrorBag);
        $html = Blade::render('<x-ph-address prefix="recipient" :values="$v" />', ['v' => [
            'state' => 'Metro Manila', 'city' => 'Manila', 'district' => 'Ermita', 'barangay' => '',
            'postcode' => '1000', 'codes' => ['state' => '130000000', 'city' => '133900000'],
        ]]);

        foreach (['state', 'city', 'district', 'barangay'] as $f) {
            $this->assertStringContainsString('name="recipient['.$f.']"', $html);
        }
        foreach (['state', 'city', 'barangay'] as $f) {
            $this->assertStringContainsString('name="recipient[codes]['.$f.']"', $html);
        }
        $this->assertStringContainsString('name="recipient[postcode]"', $html);
        $this->assertSame(4, substr_count($html, '<select'));
        $this->assertStringContainsString('Province', $html);
        $this->assertStringContainsString('Choose from list', $html);
        $this->assertStringContainsString('x-data="phAddress(', $html);
        $this->assertStringNotContainsString('<style', $html);
        $this->assertStringNotContainsString('style="', $html);
    }

    public function test_component_preselects_by_code_by_name_and_falls_back_to_text(): void
    {
        view()->share('errors', new ViewErrorBag);

        $hidden = fn (string $html, string $name) => preg_match('/name="'.preg_quote($name, '/').'"[^>]*value="([^"]*)"/', $html, $m) ? $m[1] : null;

        $byName = Blade::render('<x-ph-address prefix="r" :values="$v" />', ['v' => ['state' => 'metro manila', 'city' => 'MANILA', 'district' => 'Ermita']]);
        $this->assertSame('130000000', $hidden($byName, 'r[codes][state]'));
        $this->assertSame('133900000', $hidden($byName, 'r[codes][city]'));

        $legacy = Blade::render('<x-ph-address prefix="r" :values="$v" />', ['v' => ['state' => 'Metro Manila', 'city' => 'Atlantis']]);
        $this->assertSame('130000000', $hidden($legacy, 'r[codes][state]'));
        $this->assertSame('', $hidden($legacy, 'r[codes][city]'));
        $this->assertSame('Atlantis', $hidden($legacy, 'r[city]'));

        $byCode = Blade::render('<x-ph-address prefix="r" :values="$v" />', ['v' => ['state' => 'x', 'city' => 'y', 'codes' => ['state' => '130000000', 'city' => '133900000']]]);
        $this->assertSame('133900000', $hidden($byCode, 'r[codes][city]'));
    }
}
