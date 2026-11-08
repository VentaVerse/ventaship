<?php

namespace Tests\Feature\Ui;

use App\Couriers\CourierRegistry;
use App\Couriers\FakeCourier;
use App\Models\Admin;
use App\Models\AuditEntry;
use App\Models\CourierAccount;
use App\Models\Shipment;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\BuildsShipments;
use Tests\TestCase;

class ShipmentCreateTest extends TestCase
{
    use BuildsShipments, RefreshDatabase;

    private CourierAccount $account;

    private FakeCourier $fake;

    protected function setUp(): void
    {
        parent::setUp();
        $this->account = $this->fakeDefaultAccount();
        $this->fake = FakeCourier::make('quadx');
        CourierRegistry::fake('quadx', $this->fake);
        Route::get('/shipments/{shipment}', fn () => 'stub')->name('shipments.show')->middleware('web');
        Route::getRoutes()->refreshNameLookups();
        $this->actingAs(Admin::query()->create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'correct-horse-battery']));
    }

    private function sender(): void
    {
        $settings = app(Settings::class);
        foreach (['contact_name' => 'Venta Warehouse', 'contact_phone' => '09181234567', 'address_1' => '5 Shaw Blvd', 'city' => 'Mandaluyong', 'state' => 'Metro Manila', 'postcode' => '1550'] as $k => $v) {
            $settings->set("pickup.{$k}", $v);
        }
    }

    /** The token a rendered form carries. */
    private function token(): string
    {
        $html = $this->get('/shipments/new')->assertOk()->getContent();
        preg_match('/name="form_token" value="([0-9a-f-]{36})"/', $html, $m);
        $this->assertNotEmpty($m, 'form_token missing');

        return $m[1];
    }

    private function form(array $over = [], ?string $token = null): array
    {
        return array_replace_recursive([
            'form_token' => $token ?? $this->token(),
            'account_id' => $this->account->public_id,
            'service' => '',
            'client_reference' => '',
            'recipient' => ['first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'phone' => '0917 123 4567', 'email' => '',
                'address_1' => '1 Rizal St', 'address_2' => '', 'barangay' => 'Poblacion', 'city' => 'Makati', 'state' => 'Metro Manila', 'postcode' => '1210'],
            'items' => [['name' => 'Blue Tee', 'quantity' => '2', 'price' => '500.00']],
            'parcel' => ['weight_kg' => '1.2', 'type' => '', 'length_cm' => '', 'width_cm' => '', 'height_cm' => ''],
            'payment' => ['method' => 'prepaid', 'cod_amount' => '', 'declared_value' => '1000'],
            'pickup' => ['contact_name' => 'Venta Warehouse', 'contact_phone' => '09181234567', 'address_1' => '5 Shaw Blvd', 'address_2' => '',
                'barangay' => '', 'city' => 'Mandaluyong', 'state' => 'Metro Manila', 'postcode' => '1550'],
        ], $over);
    }

    public function test_form_renders_with_sender_prefilled_and_accounts_listed(): void
    {
        $this->sender();
        CourierAccount::factory()->create(['courier' => 'spx', 'label' => 'Second', 'status' => 'disabled']);

        $this->get('/shipments/new')->assertOk()
            ->assertSeeText(['Add shipment', 'Courier', 'Customer', 'Items', 'Parcel', 'Payment', 'Sender', 'Order reference', 'QuadX · Main QUADX', 'Add item'])
            ->assertSee('value="Venta Warehouse"', false)
            ->assertSee('value="5 Shaw Blvd"', false)
            ->assertSee('role="radiogroup"', false)
            ->assertDontSeeText('Second');

        $this->assertDoesNotMatchRegularExpression('/name="insure"[^>]*checked/', $this->get('/shipments/new')->getContent());
    }

    public function test_no_accounts_shows_empty_state(): void
    {
        CourierAccount::query()->delete();

        $this->get('/shipments/new')->assertOk()
            ->assertSeeText('No courier accounts')
            ->assertDontSee('name="form_token"', false);
    }

    public function test_store_books_with_fake_courier_and_redirects_to_show(): void
    {
        $this->fake->booksAs('QX-UI-1');

        $res = $this->post('/shipments', $this->form());

        $s = Shipment::query()->sole();
        $res->assertRedirect(route('shipments.show', $s))->assertSessionHas('status', 'Booked · QX-UI-1');
        $this->assertSame('booked', $s->state);
        $this->assertSame('manual', $s->source);
        $this->assertNull($s->client_app_id);
        $this->assertSame('09171234567', $s->recipient['phone']);
        $this->assertSame('prepaid', $s->payment_method);
        $this->assertFalse($s->options['insure']);
        $this->assertSame(0, DB::table('jobs')->count());

        $audit = AuditEntry::query()->where('action', 'shipment.created')->sole();
        $this->assertSame('admin', $audit->actor_type);
        $this->assertSame((string) auth()->id(), $audit->actor_id);
        $this->assertSame('shipment', $audit->subject_type);
        $this->assertSame($s->public_id, $audit->subject_id);
        $this->assertStringNotContainsString('Juan', json_encode($audit->changes));
        $this->assertStringNotContainsString('0917', json_encode($audit->changes));
    }

    public function test_cod_amount_required_only_for_cod(): void
    {
        $this->post('/shipments', $this->form(['payment' => ['method' => 'cod', 'cod_amount' => '']]))
            ->assertSessionHasErrors('payment.cod_amount');
        $this->assertSame(0, Shipment::query()->count());

        $this->post('/shipments', $this->form(['payment' => ['method' => 'cod', 'cod_amount' => '1290.00']]))
            ->assertSessionHasNoErrors();
        $s = Shipment::query()->sole();
        $this->assertSame('cod', $s->payment_method);
        $this->assertSame('1290.00', $s->cod_amount);

        $this->post('/shipments', $this->form(['payment' => ['method' => 'prepaid', 'cod_amount' => '']]))
            ->assertSessionHasNoErrors();
    }

    public function test_validation_errors_keep_input(): void
    {
        $token = $this->token();
        $this->from('/shipments/new')->post('/shipments', $this->form(['recipient' => ['first_name' => '', 'city' => 'Taguig'], 'items' => [['name' => 'Kept Cap']]], $token))
            ->assertRedirect('/shipments/new')
            ->assertSessionHasErrors(['recipient.first_name']);
        $this->assertSame(0, Shipment::query()->count());

        $this->get('/shipments/new')->assertSee('Taguig')->assertSee('Kept Cap');
    }

    public function test_generated_reference_format_and_uniqueness(): void
    {
        $this->post('/shipments', $this->form());
        $ref = Shipment::query()->sole()->client_reference;
        $this->assertMatchesRegularExpression('/^M-'.now()->format('Ymd').'-[A-Z0-9]{6}$/', $ref);

        $this->post('/shipments', $this->form());
        $refs = Shipment::query()->pluck('client_reference');
        $this->assertCount(2, $refs->unique());

        $this->post('/shipments', $this->form(['client_reference' => 'MY-REF-1']));
        $this->assertSame(1, Shipment::query()->where('client_reference', 'MY-REF-1')->count());

        $this->post('/shipments', $this->form(['client_reference' => 'MY-REF-1']))
            ->assertSessionHasErrors('client_reference');
        $this->assertSame(1, Shipment::query()->where('client_reference', 'MY-REF-1')->count());
    }

    public function test_double_submit_same_token_creates_one(): void
    {
        $token = $this->token();
        $this->fake->booksAs('QX-ONCE');

        $this->post('/shipments', $this->form([], $token));
        $s = Shipment::query()->sole();
        $this->post('/shipments', $this->form([], $token))->assertRedirect(route('shipments.show', $s));

        $this->assertSame(1, Shipment::query()->count());
        $this->assertCount(1, $this->fake->callsTo('createShipment'));
    }

    public function test_unknown_token_is_refused_and_keeps_input(): void
    {
        $this->from('/shipments/new')->post('/shipments', $this->form([], '11111111-1111-4111-8111-111111111111'))
            ->assertRedirect('/shipments/new')
            ->assertSessionHasErrors(['form_token' => 'Form expired.']);

        $this->assertSame(0, Shipment::query()->count());
        $this->get('/shipments/new')->assertSee('Blue Tee')->assertSee('Form expired.');
    }

    public function test_courier_refusal_saves_failed_and_shows_message(): void
    {
        $this->fake->refusesWith('Barangay not serviceable');

        $res = $this->post('/shipments', $this->form());

        $s = Shipment::query()->sole();
        $this->assertSame('failed', $s->state);
        $res->assertRedirect(route('shipments.show', $s))->assertSessionHas('status', 'Courier refused: Barangay not serviceable');
    }

    public function test_timeout_saves_unknown(): void
    {
        $this->fake->throws(new ConnectionException('cURL error 28: timed out'));

        $res = $this->post('/shipments', $this->form());

        $s = Shipment::query()->sole();
        $this->assertSame('unknown', $s->state);
        $this->assertTrue($s->needs_attention);
        $res->assertRedirect(route('shipments.show', $s))->assertSessionHas('status', 'Booking result unknown');
        $this->assertCount(1, $this->fake->callsTo('createShipment'));
    }

    public function test_redirects_to_list_when_show_route_is_missing(): void
    {
        $this->app['router']->getRoutes()->refreshNameLookups();
        $routes = Route::getRoutes();
        $routes->getByName('shipments.show')?->name('shipments.show-stub-removed');
        $routes->refreshNameLookups();

        $this->post('/shipments', $this->form())->assertRedirect(route('fulfillment'));
    }

    public function test_guest_cannot_store(): void
    {
        auth()->logout();
        Queue::fake();

        $this->post('/shipments', $this->form([], 'x'))->assertRedirect('/login');
        $this->get('/shipments/new')->assertRedirect('/login');
        $this->assertSame(0, Shipment::query()->count());
    }

    public function test_form_renders_customer_and_sender_inputs_without_stray_directives(): void
    {
        $this->sender();

        $html = $this->get('/shipments/new')->assertOk()->getContent();

        foreach (['recipient[first_name]', 'recipient[phone]', 'pickup[contact_name]', 'pickup[city]'] as $name) {
            $this->assertStringContainsString('name="'.$name.'"', $html);
        }
        $this->assertStringNotContainsString('@required(', $html);
        $this->assertStringNotContainsString('$meta', $html);
    }

    public function test_stale_session_double_submit_redirects_via_cache_and_books_once(): void
    {
        $token = $this->token();
        $this->fake->booksAs('QX-STALE');
        $this->post('/shipments', $this->form([], $token));
        $s = Shipment::query()->sole();

        $this->withSession(['shipment_form_tokens' => [$token => null]])
            ->post('/shipments', $this->form([], $token))
            ->assertRedirect(route('shipments.show', $s));

        $this->assertSame(1, Shipment::query()->count());
        $this->assertCount(1, $this->fake->callsTo('createShipment'));
    }

    public function test_submit_while_lock_held_is_refused(): void
    {
        $token = $this->token();
        $lock = \Illuminate\Support\Facades\Cache::lock('shipment-form:'.$token, 60);
        $this->assertTrue($lock->get());

        $this->post('/shipments', $this->form([], $token))->assertSessionHasErrors(['form_token' => 'Already submitting.']);

        $this->assertSame(0, Shipment::query()->count());
        $this->assertSame([], $this->fake->callsTo('createShipment'));
        $lock->release();
    }

    public function test_insure_stays_checked_after_validation_error(): void
    {
        $this->from('/shipments/new')->post('/shipments', $this->form(['insure' => '1', 'recipient' => ['first_name' => '']]))
            ->assertSessionHasErrors('recipient.first_name');

        $this->assertMatchesRegularExpression('/type="checkbox"[^>]*name="insure"[^>]*checked/', $this->get('/shipments/new')->getContent());
    }

    public function test_blank_reference_is_not_flashed_back_on_error(): void
    {
        $this->from('/shipments/new')->post('/shipments', $this->form(['recipient' => ['first_name' => '']]))
            ->assertSessionHasErrors('recipient.first_name');

        $this->assertDoesNotMatchRegularExpression('/name="client_reference"[^>]*value="M-/', $this->get('/shipments/new')->getContent());
    }

    public function test_post_without_active_accounts_is_refused(): void
    {
        $form = $this->form();
        CourierAccount::query()->delete();

        $this->post('/shipments', $form)->assertSessionHasErrors();

        $this->assertSame(0, Shipment::query()->count());
    }
}
