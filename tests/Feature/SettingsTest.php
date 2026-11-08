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

    public function test_json_looking_strings_round_trip_with_type(): void
    {
        $settings = new Settings;
        $settings->set('shipments.courier_ref_prefix', '2024');
        $settings->set('x', 'null');
        $settings->set('y', ['a' => 1]);

        $fresh = new Settings;
        $this->assertSame('2024', $fresh->get('shipments.courier_ref_prefix'));
        $this->assertSame('null', $fresh->get('x', 'dflt'));
        $this->assertSame(['a' => 1], $fresh->get('y'));
    }

    public function test_scoped_instance_resets_and_sees_direct_writes(): void
    {
        $first = app(Settings::class);
        $this->assertSame($first, app(Settings::class));
        $this->assertFalse($first->bool('safety.allow_live_spx'));

        Setting::create(['key' => 'safety.allow_live_spx', 'value' => 'true']);
        $this->assertFalse($first->bool('safety.allow_live_spx'));

        app()->forgetScopedInstances();

        $second = app(Settings::class);
        $this->assertNotSame($first, $second);
        $this->assertTrue($second->bool('safety.allow_live_spx'));
    }
}
