<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_bool_defaults_false_and_persists(): void
    {
        $settings = new Settings;
        $this->assertFalse($settings->bool('safety.allow_live_lbc'));
        $this->assertTrue($settings->bool('safety.allow_live_lbc', true));

        $settings->set('safety.allow_live_lbc', true);

        $this->assertTrue($settings->bool('safety.allow_live_lbc'));
        $this->assertTrue((new Settings)->bool('safety.allow_live_lbc'));
        $this->assertSame('true', Setting::where('key', 'safety.allow_live_lbc')->value('value'));

        $settings->set('safety.allow_live_lbc', false);
        $this->assertFalse((new Settings)->bool('safety.allow_live_lbc'));
    }

    public function test_get_default_strings_and_scalars(): void
    {
        $settings = new Settings;
        $this->assertSame('VS', $settings->get('shipments.courier_ref_prefix', 'VS'));

        $settings->set('shipments.courier_ref_prefix', 'AB');
        $settings->set('privacy.retention_days', 90);
        $settings->set('x.list', ['a' => 1]);

        $fresh = new Settings;
        $this->assertSame('AB', $fresh->get('shipments.courier_ref_prefix'));
        $this->assertSame(90, $fresh->get('privacy.retention_days', 180));
        $this->assertSame(['a' => 1], $fresh->get('x.list'));
        $this->assertSame(1, Setting::where('key', 'privacy.retention_days')->count());
    }

    public function test_bound_as_singleton(): void
    {
        $this->assertSame(app(Settings::class), app(Settings::class));
    }
}
