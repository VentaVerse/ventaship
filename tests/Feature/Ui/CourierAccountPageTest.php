<?php

namespace Tests\Feature\Ui;

use App\Couriers\AccountSettings;
use App\Couriers\CourierAccounts;
use App\Couriers\CourierRegistry;
use App\Couriers\FakeCourier;
use App\Models\Admin;
use App\Models\AuditEntry;
use App\Models\CourierAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The courier account page: the header follows the toggle, an unset secret is an
 * empty input, Save and test, the first Save creates the account, JSON settings in a code box.
 */
class CourierAccountPageTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'typed-secret-Zr4Nw8Yq1Tx6';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        CourierRegistry::clearFakes();
        parent::tearDown();
    }

    private ?Admin $admin = null;

    private function admin(): Admin
    {
        return $this->admin ??= Admin::query()->create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'correct-horse-battery']);
    }

    private function account(string $courier = 'quadx', array $attrs = []): CourierAccount
    {
        return CourierAccount::factory()->create(['courier' => $courier, 'label' => 'Main', 'mode' => 'sandbox', 'is_default' => true, 'default_slot' => $courier, ...$attrs]);
    }

    private function badge(string $html, string $attr): string
    {
        $this->assertSame(1, preg_match('/<span class="x-badge x-badge--(\w+)"[^>]*' . $attr . '[^>]*>(?:\s|<[^>]+>)*([^<]+)</', $html, $m), $attr);

        return $m[1] . ' ' . trim($m[2]);
    }

    /** The config an Alpine component on the page is started with (rendered by Js::from). @return array<string, mixed> */
    private function alpineConfig(string $html, string $component): array
    {
        $this->assertSame(1, preg_match('/' . $component . "\\(JSON\\.parse\\('([^']*)'\\)\\)/", $html, $m), $component);

        return json_decode(json_decode('"' . $m[1] . '"'), true, flags: JSON_THROW_ON_ERROR);
    }

    private function noSecretLeft(): void
    {
        $this->assertFalse(session()->hasOldInput('secrets'));
        $this->assertStringNotContainsString(self::SECRET, json_encode(session()->all()));
        $this->assertStringNotContainsString(self::SECRET, AuditEntry::query()->get()->toJson());
    }

    // --- Header -------------------------------------------------------------------------------------------------

    public function test_header_shows_the_saved_environment_and_follows_the_form(): void
    {
        $this->account('quadx', ['mode' => 'production', 'last_check_ok' => true]);

        $html = $this->actingAs($this->admin())->get(route('accounts.show', 'quadx'))->assertOk()->getContent();

        // Production is amber (as on the list), Sandbox blue; the saved check while the saved environment is selected.
        $this->assertSame('attn Production', $this->badge($html, 'data-env-badge'));
        $this->assertSame('good Connected', $this->badge($html, 'data-check-badge'));
        $this->assertMatchesRegularExpression('/<span class="ac-unsaved" data-unsaved\s+hidden\s+x-bind:hidden="!unsaved">Unsaved<\/span>/', $html);
        $this->assertStringContainsString('x-data="accountHeader(', $html);
        $this->assertStringContainsString('x-on:account-form.window="sync($event.detail)"', $html);
        $this->assertStringContainsString('x-bind:class="toneClasses(view.envTone)"', $html);
        $this->assertStringContainsString('x-text="view.checkLabel"', $html);
        $this->assertSame(['env' => 'production', 'saved' => 'production', 'check' => ['Connected', 'good'], 'unsaved' => false], $this->alpineConfig($html, 'accountHeader'));

        // Test connection: enabled with nothing unsaved, disabled (attribute and aria) while the form is unsaved.
        $this->assertMatchesRegularExpression('/<button type="submit"\s+class="x-btn x-btn--default x-btn--md" data-test-connection="data-test-connection" x-bind:disabled="unsaved" x-bind:aria-disabled="unsaved \? \'true\' : null">\s*Test connection/', $html);

        // The form announces what the header follows.
        $this->assertStringContainsString('x-data="accountForm(', $html);
        $this->assertStringContainsString('x-on:input="refresh()" x-on:change="refresh()"', $html);
    }

    public function test_a_refused_save_comes_back_unsaved_with_the_selected_environment(): void
    {
        $a = $this->account('quadx', ['mode' => 'sandbox', 'last_check_ok' => true]);

        $this->actingAs($this->admin())->from(route('accounts.show', 'quadx'))->put(route('accounts.update', 'quadx'), [
            'mode' => 'production', 'settings' => ['quadx_default_parcel' => 'nope'],
        ])->assertSessionHasErrors('settings.quadx_default_parcel');
        $this->assertSame('sandbox', $a->fresh()->mode);

        $html = $this->get(route('accounts.show', 'quadx'))->assertOk()->getContent();

        $this->assertSame('attn Production', $this->badge($html, 'data-env-badge'));
        $this->assertSame('mute Not checked', $this->badge($html, 'data-check-badge'));
        $this->assertMatchesRegularExpression('/<span class="ac-unsaved" data-unsaved\s+x-bind:hidden="!unsaved">Unsaved<\/span>/', $html);
        $this->assertMatchesRegularExpression('/<button type="submit"\s+disabled\s[^>]*data-test-connection/', $html);
        $this->assertTrue($this->alpineConfig($html, 'accountForm')['dirty']);
        $this->assertTrue($this->alpineConfig($html, 'accountHeader')['unsaved']);
    }

    public function test_no_account_header_shows_the_toggle_environment_and_not_set_up(): void
    {
        $html = $this->actingAs($this->admin())->get(route('accounts.show', 'lbc'))->assertOk()->getContent();

        $this->assertSame('cool Sandbox', $this->badge($html, 'data-env-badge'));
        $this->assertSame('mute Not set up', $this->badge($html, 'data-check-badge'));
        $this->assertStringNotContainsString('data-test-connection', $html);
        $this->assertSame(['env' => 'sandbox', 'saved' => null, 'check' => ['Not set up', 'mute'], 'unsaved' => false], $this->alpineConfig($html, 'accountHeader'));
    }

    // --- Secrets ------------------------------------------------------------------------------------------------

    public function test_unset_secret_is_an_empty_input_and_a_saved_one_stays_masked(): void
    {
        $a = $this->account('spx');
        app(CourierAccounts::class)->setSetting($a, 'spx_sandbox_secret_key', 'app-secret-value-7c1f');

        $html = $this->actingAs($this->admin())->get(route('accounts.show', 'spx'))->assertOk()
            ->assertDontSee('app-secret-value')->assertSee('7c1f')->getContent();

        // Saved: masked, View and Edit, no name until edited.
        $this->assertStringContainsString('aria-label="View Sandbox App Secret"', $html);
        $this->assertStringContainsString('aria-label="Edit Sandbox App Secret"', $html);
        $this->assertStringNotContainsString('name="secrets[spx_sandbox_secret_key]"', $html);
        $this->assertStringContainsString('x-ref="editButton"', $html);
        $this->assertStringContainsString('x-ref="viewButton"', $html);
        $this->assertStringContainsString('x-on:click="hide(true)"', $html);

        // Not set: an empty named input as wide as the plain fields, no badge, no Edit.
        $this->assertMatchesRegularExpression('/<input type="text" class="x-input st-ctl x-mono" id="f-key-spx_sandbox_user_secret" name="secrets\[spx_sandbox_user_secret\]" value=""/', $html);
        $this->assertStringNotContainsString('aria-label="Edit Sandbox User Secret"', $html);
        $this->assertStringNotContainsString('ac-cred__unset', $html);
    }

    public function test_a_typed_unset_secret_saves_and_an_untouched_one_changes_nothing(): void
    {
        $a = $this->account('spx');
        app(CourierAccounts::class)->setSetting($a, 'spx_sandbox_secret_key', 'app-secret-value-7c1f');
        AuditEntry::query()->delete();

        // Untouched: the unset input posts an empty value; the saved one posts nothing.
        $this->actingAs($this->admin())->put(route('accounts.update', 'spx'), [
            'mode' => 'sandbox', 'secrets' => ['spx_sandbox_user_secret' => '', 'spx_production_user_secret' => '  '],
        ])->assertSessionHas('status', 'Saved');
        $this->assertSame(0, AuditEntry::query()->count());
        $settings = AccountSettings::for($a);
        $this->assertNull($settings->secret('spx_sandbox_user_secret'));
        $this->assertSame('app-secret-value-7c1f', $settings->secret('spx_sandbox_secret_key'));

        $this->put(route('accounts.update', 'spx'), [
            'mode' => 'sandbox', 'secrets' => ['spx_sandbox_user_secret' => self::SECRET],
        ])->assertSessionHas('status', 'Saved');
        $this->assertSame(self::SECRET, AccountSettings::for($a)->secret('spx_sandbox_user_secret'));
        $this->noSecretLeft();
        $this->get(route('accounts.show', 'spx'))->assertOk()->assertDontSee(self::SECRET)
            ->assertSee('aria-label="Edit Sandbox User Secret"', false);
    }

    // --- Save and test ------------------------------------------------------------------------------------------

    public function test_save_bar_has_discard_save_and_save_and_test(): void
    {
        $this->account('quadx');

        $html = $this->actingAs($this->admin())->get(route('accounts.show', 'quadx'))->assertOk()
            ->assertSeeInOrder(['Discard', 'Save', 'Save and test'])->getContent();

        $this->assertMatchesRegularExpression('/<button type="submit"\s+class="x-btn x-btn--default x-btn--md" data-save="data-save">Save<\/button>/', $html);
        $this->assertMatchesRegularExpression('/<button type="submit"\s+class="x-btn x-btn--primary x-btn--md" name="after" value="test" data-save-test="data-save-test">Save and test<\/button>/', $html);
    }

    public function test_save_and_test_saves_then_tests_the_newly_saved_environment(): void
    {
        $a = $this->account('quadx', ['mode' => 'sandbox']);
        $seen = new \ArrayObject;
        CourierRegistry::fake('quadx', new class($seen) extends FakeCourier
        {
            public function __construct(private \ArrayObject $seen) {}

            public function testConnection(): array
            {
                // What the check runs against: the account as saved by then.
                $account = CourierAccount::query()->where('courier', 'quadx')->firstOrFail();
                $this->seen->exchangeArray([$account->mode, AccountSettings::for($account)->secret('quadx_production_api_key')]);

                return ['ok' => true, 'message' => 'ok'];
            }
        });

        $this->actingAs($this->admin())->put(route('accounts.update', 'quadx'), [
            'mode' => 'production', 'after' => 'test', 'secrets' => ['quadx_production_api_key' => self::SECRET],
        ])->assertRedirect(route('accounts.show', 'quadx'))->assertSessionHas('status', 'Saved · Connected')->assertSessionHasNoErrors();

        $this->assertSame(['production', self::SECRET], $seen->getArrayCopy());
        $a->refresh();
        $this->assertSame('production', $a->mode);
        $this->assertTrue($a->last_check_ok);
        $this->assertNotNull($a->last_check_at);
        $this->noSecretLeft();
    }

    public function test_save_and_test_shows_a_failed_check_the_way_test_connection_does(): void
    {
        $a = $this->account('quadx');
        CourierRegistry::fake('quadx', FakeCourier::make()->throws(new ConnectionException('timeout'), 'testConnection'));

        $this->actingAs($this->admin())->put(route('accounts.update', 'quadx'), [
            'mode' => 'sandbox', 'after' => 'test', 'settings' => ['quadx_default_parcel' => 'box'],
        ])->assertRedirect(route('accounts.show', 'quadx'))
            ->assertSessionHas('status', 'Saved')
            ->assertSessionHasErrors(['test' => 'Check failed: No answer from QuadX.']);

        $this->assertSame('box', AccountSettings::for($a)->read('quadx_default_parcel'));
        $this->assertFalse($a->refresh()->last_check_ok);
    }

    public function test_save_and_test_does_not_test_when_the_save_is_refused(): void
    {
        $a = $this->account('quadx');
        $fake = FakeCourier::make();
        CourierRegistry::fake('quadx', $fake);

        $this->actingAs($this->admin())->from(route('accounts.show', 'quadx'))->put(route('accounts.update', 'quadx'), [
            'mode' => 'sandbox', 'after' => 'test', 'settings' => ['quadx_default_parcel' => 'nope'],
            'secrets' => ['quadx_sandbox_api_key' => self::SECRET],
        ])->assertSessionHasErrors('settings.quadx_default_parcel')->assertSessionMissing('status');

        $this->assertSame([], $fake->callsTo('testConnection'));
        $this->assertNull($a->refresh()->last_check_at);
        $this->assertNull(AccountSettings::for($a)->secret('quadx_sandbox_api_key'));
        $this->noSecretLeft();

        $this->put(route('accounts.update', 'quadx'), ['mode' => 'sandbox', 'after' => 'later'])->assertSessionHasErrors('after');
    }

    // --- First save creates the account -------------------------------------------------------------------------

    public function test_first_save_creates_the_account_with_its_keys(): void
    {
        $admin = $this->admin();
        $this->assertNull(CourierAccount::query()->first());

        $this->actingAs($admin)->put(route('accounts.update', 'lbc'), [
            'mode' => 'sandbox',
            'settings' => ['lbc_client_code' => 'AB123', 'lbc_shipper_account_no' => ''],
            'secrets' => ['lbc_sandbox_common_key' => self::SECRET, 'lbc_sandbox_track_key' => ''],
        ])->assertRedirect(route('accounts.show', 'lbc'))->assertSessionHas('status', 'Saved');

        $a = CourierAccount::query()->sole();
        $this->assertSame(['lbc', 'LBC', 'sandbox', true, 'lbc'], [$a->courier, $a->label, $a->mode, $a->is_default, $a->default_slot]);
        $settings = AccountSettings::for($a);
        $this->assertSame(self::SECRET, $settings->secret('lbc_sandbox_common_key'));
        $this->assertNull($settings->secret('lbc_sandbox_track_key'));
        $this->assertSame('AB123', $settings->read('lbc_client_code'));
        foreach (['courier_account.created', 'courier_account.setting_changed'] as $action) {
            $this->assertSame((string) $admin->id, AuditEntry::query()->where('action', $action)->latest('id')->firstOrFail()->actor_id, $action);
        }
        $this->noSecretLeft();

        // The page now shows the saved key masked, and the account's own sections.
        $this->get(route('accounts.show', 'lbc'))->assertOk()->assertDontSee(self::SECRET)
            ->assertSee('aria-label="Edit Sandbox Common API key"', false)->assertSee('Number blocks')->assertSee('Test connection');
    }

    public function test_first_save_and_test_creates_then_tests(): void
    {
        CourierRegistry::fake('quadx', $fake = FakeCourier::make());

        $this->actingAs($this->admin())->put(route('accounts.update', 'quadx'), [
            'mode' => 'production', 'after' => 'test', 'secrets' => ['quadx_production_api_key' => self::SECRET],
        ])->assertSessionHas('status', 'Saved · Connected');

        $a = CourierAccount::query()->sole();
        $this->assertSame('production', $a->mode);
        $this->assertTrue($a->last_check_ok);
        $this->assertCount(1, $fake->callsTo('testConnection'));
    }

    public function test_a_refused_first_save_leaves_no_account(): void
    {
        $this->actingAs($this->admin())->from(route('accounts.show', 'quadx'))->put(route('accounts.update', 'quadx'), [
            'mode' => 'sandbox',
            'settings' => ['quadx_default_parcel' => 'nope'],
            'secrets' => ['quadx_sandbox_api_key' => self::SECRET],
        ])->assertRedirect(route('accounts.show', 'quadx'))->assertSessionHasErrors('settings.quadx_default_parcel');

        $this->assertSame(0, CourierAccount::query()->withTrashed()->count());
        $this->assertSame(0, AuditEntry::query()->count());
        $this->noSecretLeft();

        // The page comes back as the same unsaved form, the refused value in place.
        $html = $this->get(route('accounts.show', 'quadx'))->assertOk()->assertSee('Sandbox keys')->assertSee('The value must be one of')
            ->assertDontSee(self::SECRET)->getContent();
        $this->assertMatchesRegularExpression('/<span class="ac-unsaved" data-unsaved\s+x-bind:hidden="!unsaved">Unsaved<\/span>/', $html);
        $this->assertStringContainsString('name="secrets[quadx_sandbox_api_key]" value=""', $html);
    }

    public function test_no_account_page_shows_keys_and_defaults_but_not_the_webhook_or_blocks(): void
    {
        config(['app.url' => 'https://ship.example.com']);

        $html = $this->actingAs($this->admin())->get(route('accounts.show', 'spx'))->assertOk()
            ->assertSee('Sandbox keys')->assertSee('Production keys')->assertSee('Booking defaults')->assertSee('Pouch presets')
            ->assertDontSee('Webhook')->assertDontSee('Number blocks')->getContent();
        $this->assertMatchesRegularExpression('/data-keys="production"[^>]*\shidden[\s>]/', $html);
        $this->assertStringContainsString('name="secrets[spx_sandbox_secret_key]"', $html);
        $this->assertStringContainsString('name="settings[spx_sandbox_app_id]"', $html);
        $form = $this->alpineConfig($html, 'accountForm');
        $this->assertSame(['sandbox', null, false], [$form['env'], $form['saved'], $form['dirty']]);
        $this->assertSame('Switch SPX to Production? Bookings will be real.', $form['confirm']['title']);

        $this->get(route('accounts.show', 'lbc'))->assertOk()->assertSee('API gateway')->assertDontSee('Number blocks')->assertDontSee('Add block');
    }

    // --- JSON settings ------------------------------------------------------------------------------------------

    public function test_json_setting_is_a_pretty_printed_code_box_and_round_trips(): void
    {
        $a = $this->account('spx');
        $compact = '[{"label":"Small","length_cm":25,"width_cm":20,"height_cm":10,"max_weight_kg":3},{"label":"Medium","length_cm":35,"width_cm":25,"height_cm":15,"max_weight_kg":5}]';
        AccountSettings::for($a)->write('spx_pouch_presets', $compact);
        AuditEntry::query()->delete();

        $html = $this->actingAs($this->admin())->get(route('accounts.show', 'spx'))->assertOk()
            ->assertDontSee('(JSON)')->getContent();
        $pretty = "[\n  {\"label\": \"Small\", \"length_cm\": 25, \"width_cm\": 20, \"height_cm\": 10, \"max_weight_kg\": 3},\n  {\"label\": \"Medium\", \"length_cm\": 35, \"width_cm\": 25, \"height_cm\": 15, \"max_weight_kg\": 5}\n]";
        $this->assertMatchesRegularExpression('/<label class="fm-label"\s+for="f-set-spx_pouch_presets"\s*>\s*Pouch presets\s*<\/label>/', $html);
        $this->assertMatchesRegularExpression('/<textarea[^>]*class="x-input st-ctl x-mono ac-json"[^>]*name="settings\[spx_pouch_presets\]"[^>]*rows="4"[^>]*>/', $html);
        $this->assertStringContainsString('>' . e($pretty) . '</textarea>', $html);

        // Posted back as shown: nothing changes.
        $this->put(route('accounts.update', 'spx'), ['mode' => 'sandbox', 'settings' => ['spx_pouch_presets' => str_replace("\n", "\r\n", $pretty)]])
            ->assertSessionHasNoErrors()->assertSessionHas('status', 'Saved');
        $this->assertSame(0, AuditEntry::query()->count());
        $this->assertSame($compact, AccountSettings::for($a)->read('spx_pouch_presets'));

        // An edit is stored compact.
        $this->put(route('accounts.update', 'spx'), ['mode' => 'sandbox', 'settings' => ['spx_pouch_presets' => "[\n  {\"label\": \"Tiny\", \"length_cm\": 10.0, \"width_cm\": 10, \"height_cm\": 5, \"max_weight_kg\": 1}\n]"]])
            ->assertSessionHasNoErrors();
        $this->assertSame('[{"label":"Tiny","length_cm":10.0,"width_cm":10,"height_cm":5,"max_weight_kg":1}]', AccountSettings::for($a)->read('spx_pouch_presets'));
        $this->assertSame(1, AuditEntry::query()->where('action', 'courier_account.setting_changed')->count());
    }

    public function test_invalid_json_is_an_inline_error_and_comes_back_as_typed(): void
    {
        $a = $this->account('spx');
        AccountSettings::for($a)->write('spx_pouch_presets', '[]');
        $typed = "[\n  {\"label\": \"Small\",\n]";

        $this->actingAs($this->admin())->from(route('accounts.show', 'spx'))->put(route('accounts.update', 'spx'), [
            'mode' => 'sandbox', 'settings' => ['spx_pouch_presets' => $typed],
        ])->assertSessionHasErrors(['settings.spx_pouch_presets' => 'Enter valid JSON.']);
        $this->assertSame('[]', AccountSettings::for($a)->read('spx_pouch_presets'));

        $html = $this->get(route('accounts.show', 'spx'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<div class="fm-error"\s+id="f-set-spx_pouch_presets-error"\s*>Enter valid JSON.<\/div>/', $html);
        $this->assertMatchesRegularExpression('/<textarea[^>]*aria-invalid="true"[^>]*>' . preg_quote(e($typed), '/') . '<\/textarea>/', $html);
    }

    public function test_json_check_lives_in_the_service(): void
    {
        $a = $this->account('spx');
        $svc = app(CourierAccounts::class);

        $svc->setSetting($a, 'spx_pouch_presets', '{"a": 1}');
        $this->assertSame('{"a":1}', AccountSettings::for($a)->read('spx_pouch_presets'));
        $svc->setSetting($a, 'spx_pouch_presets', '{}');
        $this->assertSame('{}', AccountSettings::for($a)->read('spx_pouch_presets'), 'an empty object stays an object');

        $this->expectExceptionMessage('Enter valid JSON.');
        $svc->setSetting($a, 'spx_pouch_presets', 'not json');
    }

    // --- Webhook, toggle, phones --------------------------------------------------------------------------------

    public function test_webhook_label_is_address_and_its_buttons_share_a_width(): void
    {
        config(['app.url' => 'https://ship.example.com']);
        $this->account('quadx', ['webhook_token' => str_repeat('t', 40)]);
        $this->account('spx', ['webhook_token' => str_repeat('t', 40)]);

        $html = $this->actingAs($this->admin())->get(route('accounts.show', 'quadx'))->assertOk()->assertDontSee('Address for')->getContent();

        $this->assertMatchesRegularExpression('/<label class="fm-label"\s+for="f-webhook-url"\s*>\s*Address\s*<\/label>/', $html);
        $this->assertStringContainsString('class="ac-cred__acts ac-cred__acts--even"', $html);
        $this->assertStringContainsString('aria-label="View Webhook Address"', $html);

        // SPX's tokenless address has nothing to hide: Copy only.
        $spx = $this->get(route('accounts.show', 'spx'))->assertOk()->assertDontSee('Address for')->getContent();
        $this->assertMatchesRegularExpression('/<label class="fm-label"\s+for="f-webhook-url"\s*>\s*Address\s*<\/label>/', $spx);
        $this->assertStringNotContainsString('aria-label="View Webhook Address"', $spx);
        $this->assertStringContainsString('aria-label="Copy Webhook Address"', $spx);

        $css = file_get_contents(resource_path('css/pages/accounts.css'));
        $this->assertMatchesRegularExpression('/\.ac-cred__acts--even\s*\{[^}]*grid-auto-columns:\s*minmax\(0, 1fr\)/', $css);
    }

    public function test_toggle_contrast_and_phone_targets(): void
    {
        $css = file_get_contents(resource_path('css/pages/accounts.css'));

        $this->assertMatchesRegularExpression('/\.ac-seg\s*\{[^}]*border:\s*1px solid var\(--bl-field-edge\)/', $css);
        $this->assertMatchesRegularExpression('/\.ac-seg__opt:has\(\.ac-seg__input:checked\)\s*\{[^}]*background:\s*var\(--bl-brand-btn\);[^}]*color:\s*#fff/', $css);
        $this->assertMatchesRegularExpression('/max-width:\s*640px\)\s*\{[^@]*\.ac-page \.st-ctl,[^{]*\.ac-page \.x-input,[^{]*\.ac-page \.x-select-wrap[^{]*\{\s*min-height:\s*44px/s', $css);
        $this->assertMatchesRegularExpression('/\.ac-unsaved\s*\{[^}]*color:\s*var\(--bl-attn\);[^}]*font:\s*600 12px/', $css);
    }
}
