<?php

namespace Tests\Feature\Ui;

use App\Couriers\AccountSettings;
use App\Models\Admin;
use App\Models\AuditEntry;
use App\Models\CourierAccount;
use App\Models\PickupAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** SPX Sandbox: "Create user" asks SPX (3.1 Create Account) for a User ID and User Secret, as VentaCart does. */
class SpxCreateUserTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->actingAs(Admin::query()->create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'correct-horse-battery']));
    }

    private function spx(string $mode = 'sandbox'): CourierAccount
    {
        $account = CourierAccount::factory()->create(['courier' => 'spx', 'mode' => $mode]);
        $settings = AccountSettings::for($account);
        $settings->write('spx_sandbox_app_id', '424242');
        $settings->writeSecret('spx_sandbox_secret_key', 'fake-app-secret-not-real');

        return $account;
    }

    private function pickup(string $phone = '0918 123 4567'): void
    {
        PickupAddress::query()->create(['label' => 'Main', 'is_default' => true, 'address' => ['contact_name' => 'Shop', 'contact_phone' => $phone]]);
    }

    public function test_button_shows_in_sandbox_until_a_user_id_is_saved(): void
    {
        $account = $this->spx();
        $html = $this->get(route('accounts.show', 'spx'))->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, 'form="spx-user-form"'));
        $this->assertStringContainsString('action="'.route('accounts.spx-user', 'spx').'"', $html);
        $this->assertMatchesRegularExpression('/data-keys="sandbox"[\s\S]*Create user[\s\S]*data-keys="production"/', $html, 'inside the Sandbox keys');

        AccountSettings::for($account)->write('spx_sandbox_user_id', '555001');
        $this->get(route('accounts.show', 'spx'))->assertDontSee('spx-user-form');
    }

    public function test_no_button_in_production_or_for_other_couriers(): void
    {
        $this->spx('production');
        $this->get(route('accounts.show', 'spx'))->assertOk()->assertDontSee('spx-user-form');

        CourierAccount::factory()->create(['courier' => 'quadx', 'mode' => 'sandbox']);
        $this->get(route('accounts.show', 'quadx'))->assertOk()->assertDontSee('spx-user-form');
        $this->post(route('accounts.spx-user', 'quadx'))->assertNotFound();
    }

    public function test_create_user_saves_the_pair_from_the_default_pickup_phone(): void
    {
        $account = $this->spx();
        $this->pickup();
        Http::fake(['*account/create*' => Http::response([
            'ret_code' => 0, 'message' => 'success.', 'data' => ['user_id' => 555001, 'user_secret' => 'issued-user-secret', 'password' => 'x'],
        ])]);

        $this->post(route('accounts.spx-user', 'spx'))
            ->assertRedirect(route('accounts.show', 'spx'))->assertSessionHas('status', 'User created')->assertSessionHasNoErrors();

        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://test-stable.spx.ph/')
            && $request->data() === ['phone' => '09181234567']);
        $settings = AccountSettings::for($account);
        $this->assertSame('555001', $settings->read('spx_sandbox_user_id'));
        $this->assertSame('issued-user-secret', $settings->secret('spx_sandbox_user_secret'));
        $this->assertSame(1, AuditEntry::query()->where('action', 'courier_account.spx_user_created')->count());
        $this->get(route('accounts.show', 'spx'))->assertDontSee('spx-user-form');
    }

    public function test_refusals_are_shown_under_the_button(): void
    {
        $this->spx();
        Http::fake();

        $this->post(route('accounts.spx-user', 'spx'))->assertSessionHasErrors(['spx_user' => 'Add a pickup address with a mobile number first.']);
        $this->get(route('accounts.show', 'spx'))->assertSee('id="spx-user-error"', false);

        $this->pickup();
        AccountSettings::for(CourierAccount::query()->first())->write('spx_sandbox_user_id', '555001');
        $this->post(route('accounts.spx-user', 'spx'))->assertSessionHasErrors('spx_user');
        Http::assertNothingSent();
    }

    public function test_production_is_refused(): void
    {
        $this->spx('production');
        $this->pickup();
        Http::fake();

        $this->post(route('accounts.spx-user', 'spx'))->assertSessionHasErrors(['spx_user' => 'Users can only be created in Sandbox.']);
        Http::assertNothingSent();
    }
}
