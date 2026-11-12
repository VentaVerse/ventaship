<?php

namespace Tests\Feature\Ui;

use App\Couriers\CourierRegistry;
use App\Couriers\FakeCourier;
use App\Models\Admin;
use App\Models\AuditEntry;
use App\Models\CourierAccount;
use App\Models\Customer;
use App\Models\Shipment;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\BuildsShipments;
use Tests\TestCase;

/**
 * The Add / Edit shipment form: errors under their fields only (the flash keeps
 * the rest), controls linked to their errors, plain messages, labels that match their controls, the picked customer,
 * the section order, the summary and Save draft.
 */
class ShipmentFormErrorsTest extends TestCase
{
    use BuildsShipments, RefreshDatabase;

    private CourierAccount $account;

    private FakeCourier $fake;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->account = $this->fakeDefaultAccount();
        $this->fake = FakeCourier::make('quadx');
        CourierRegistry::fake('quadx', $this->fake);
        $this->actingAs(Admin::query()->create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'correct-horse-battery']));
    }

    private function token(): string
    {
        preg_match('/name="form_token" value="([0-9a-f-]{36})"/', $this->get('/shipments/new')->assertOk()->getContent(), $m);
        $this->assertNotEmpty($m, 'form_token missing');

        return $m[1];
    }

    private function form(array $over = []): array
    {
        return array_replace_recursive([
            'form_token' => $this->token(),
            'account_id' => $this->account->public_id,
            'recipient' => ['first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'phone' => '0917 123 4567',
                'address_1' => '1 Rizal St', 'barangay' => 'Poblacion', 'city' => 'Makati', 'state' => 'Metro Manila', 'postcode' => '1210'],
            'item' => 'Blue Tee',
            'value' => '1250',
            'parcel' => ['type' => 'box'],
            'payment' => ['method' => 'cod'],
            'pickup_address_id' => $this->pickupId(),
        ], $over);
    }

    /** Posts a form with the recipient, item and packaging left empty, and returns the form page it comes back to. */
    private function failedForm(array $over = []): string
    {
        $this->from('/shipments/new')->post('/shipments', $this->form(array_replace_recursive([
            'recipient' => ['first_name' => '', 'phone' => '', 'address_1' => '', 'city' => '', 'state' => ''],
            'item' => '',
            'parcel' => ['type' => ''],
        ], $over)))->assertRedirect('/shipments/new');

        return $this->get('/shipments/new')->assertOk()->getContent();
    }

    private function xpath(string $html): DOMXPath
    {
        $doc = new DOMDocument;
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?>'.$html);
        libxml_clear_errors();

        return new DOMXPath($doc);
    }

    /** @return list<string> the flash's error lines */
    private function flashErrors(string $html): array
    {
        $out = [];
        foreach ($this->xpath($html)->query('//div[contains(@class, "x-flash__line--bad")]') as $line) {
            $out[] = trim($line->textContent);
        }

        return $out;
    }

    public function test_errors_show_under_their_fields_and_not_in_the_flash(): void
    {
        $html = $this->failedForm();

        $this->assertSame([], $this->flashErrors($html));
        $x = $this->xpath($html);
        $this->assertSame('Required.', trim($x->query('//*[@id="f-recipient-first-name-error"]')->item(0)?->textContent ?? ''));
        $this->assertSame('Required.', trim($x->query('//*[@id="f-item-error"]')->item(0)?->textContent ?? ''));
        $this->assertSame('Choose the packaging.', trim($x->query('//*[@id="f-parcel-type-error"]')->item(0)?->textContent ?? ''));
        $this->assertSame('Choose a province.', trim($x->query('//*[@id="ph-recipient-state-error"]')->item(0)?->textContent ?? ''));
        $this->assertSame('Choose a city or municipality.', trim($x->query('//*[@id="ph-recipient-city-error"]')->item(0)?->textContent ?? ''));
    }

    public function test_an_invalid_control_points_at_its_error(): void
    {
        $x = $this->xpath($this->failedForm());

        foreach (['f-recipient-first-name', 'f-recipient-phone', 'f-recipient-address-1', 'f-item', 'ph-recipient-state', 'ph-recipient-city'] as $id) {
            $control = $x->query("//*[@id='{$id}']")->item(0);
            $this->assertNotNull($control, $id);
            $this->assertSame('true', $control->getAttribute('aria-invalid'), $id);
            $this->assertSame("{$id}-error", $control->getAttribute('aria-describedby'), $id);
            $this->assertStringContainsString('is-invalid', $control->getAttribute('class'), $id);
            $this->assertSame(1, $x->query("//*[@id='{$id}-error']")->length, $id);
        }
        // The phone's red edge is on its +63 box.
        $this->assertSame(1, $x->query('//div[contains(@class, "x-phone") and contains(@class, "is-invalid")]')->length);
        // The packaging group points at its error too.
        $group = $x->query('//fieldset[@aria-describedby="f-parcel-type-error" and not(@disabled)]')->item(0);
        $this->assertNotNull($group);
        $this->assertSame('true', $group->getAttribute('aria-invalid'));
        $this->assertStringContainsString('is-invalid', $group->getAttribute('class'));

        // A valid field is not marked.
        $last = $x->query("//*[@id='f-recipient-last-name']")->item(0);
        $this->assertFalse($last->hasAttribute('aria-invalid'));
        $this->assertFalse($last->hasAttribute('aria-describedby'));
    }

    public function test_messages_never_name_a_raw_key(): void
    {
        $this->post('/shipments', $this->form([
            'recipient' => ['first_name' => '', 'last_name' => str_repeat('x', 300), 'phone' => '', 'address_1' => '', 'city' => '', 'state' => ''],
            'item' => '', 'value' => '', 'parcel' => ['type' => 'nope'], 'payment' => ['method' => 'card'], 'pickup_address_id' => '',
        ]));

        $messages = session('errors')->getBag('default')->all();
        $this->assertNotEmpty($messages);
        foreach ($messages as $message) {
            $this->assertDoesNotMatchRegularExpression('/\w\.\w|_|recipient|parcel\.|payment\./i', $message, $message);
        }
        $errors = session('errors')->getBag('default');
        $this->assertSame('Required.', $errors->first('recipient.first_name'));
        $this->assertSame('Required.', $errors->first('recipient.phone'));
        $this->assertSame('Too long.', $errors->first('recipient.last_name'));
        $this->assertSame('Choose a province.', $errors->first('recipient.state'));
        $this->assertSame('Choose a city or municipality.', $errors->first('recipient.city'));
        $this->assertSame('Choose the packaging.', $errors->first('parcel.type'));

        // The phone keeps its own message when it is filled but wrong.
        $this->post('/shipments', $this->form(['recipient' => ['phone' => '0281234567']]))
            ->assertSessionHasErrors(['recipient.phone' => 'Enter the 10-digit mobile number.']);
    }

    public function test_an_error_about_no_field_still_shows_in_the_flash(): void
    {
        $this->savedPickup();
        // A stale token: "Form expired." is about the form, not a field.
        $this->from('/shipments/new')->post('/shipments', $this->form(['form_token' => '00000000-0000-0000-0000-000000000000']))
            ->assertRedirect('/shipments/new');
        $html = $this->get('/shipments/new')->getContent();
        $this->assertSame(['Form expired.'], $this->flashErrors($html));

        // An account the booking cannot use is about the courier choice: under the tiles, not in the flash.
        $this->from('/shipments/new')->post('/shipments', $this->form(['account_id' => 'acc_missing']))
            ->assertSessionHasErrors(['account_id' => 'Not available.']);
        $html = $this->get('/shipments/new')->getContent();
        $x = $this->xpath($html);
        $this->assertSame([], $this->flashErrors($html));
        $this->assertSame('Not available.', trim($x->query('//*[@id="f-account-id-error"]')->item(0)?->textContent ?? ''));
        $group = $x->query('//*[@role="radiogroup" and @aria-labelledby="sec-courier"]')->item(0);
        $this->assertSame('f-account-id-error', $group->getAttribute('aria-describedby'));
        $this->assertSame('true', $group->getAttribute('aria-invalid'));
    }

    public function test_other_pages_keep_errors_that_have_no_field(): void
    {
        // The shipment page has no field for "Only unbooked manual shipments can be changed.".
        $s = Shipment::factory()->create(['state' => 'booked', 'source' => 'manual', 'courier_account_id' => $this->account->id, 'courier' => 'quadx']);
        $this->get(route('shipments.edit', $s->public_id))->assertRedirect();
        $this->assertSame(['Only unbooked manual shipments can be changed.'], $this->flashErrors($this->get(route('shipments.show', $s->public_id))->getContent()));

        // An account field error shows under the field only.
        $this->from(route('account'))
            ->put(route('account.profile'), ['name' => 'Ana', 'email' => 'not an email'])
            ->assertSessionHasErrors(['email' => 'Not a valid email.']);
        $errors = session('errors');
        $html = $this->get(route('account'))->getContent();
        $x = $this->xpath($html);
        $this->assertSame([], $this->flashErrors($html));
        $this->assertSame('Not a valid email.', trim($x->query('//*[@id="f-email-error"]')->item(0)?->textContent ?? ''));
        $this->assertSame('f-email-error', $x->query('//*[@id="f-email"]')->item(0)?->getAttribute('aria-describedby'));

        // The same error on a page that does not render its field still shows, in the flash.
        $html = $this->withSession(['errors' => $errors])->get(route('settings.edit', ['tab' => 'general']))->getContent();
        $this->assertSame(['Not a valid email.'], $this->flashErrors($html));
    }

    public function test_a_field_error_on_a_page_without_that_field_falls_back_to_the_flash(): void
    {
        $html = $this->withSession(['errors' => (new \Illuminate\Support\ViewErrorBag)->put('default', new \Illuminate\Support\MessageBag([
            'recipient.first_name' => ['Required.'], 'ids' => ['Nothing to print.'],
        ]))])->get('/shipments/new')->getContent();
        $this->assertSame(['Nothing to print.'], $this->flashErrors($html));

        $html = $this->withSession(['errors' => (new \Illuminate\Support\ViewErrorBag)->put('default', new \Illuminate\Support\MessageBag([
            'recipient.first_name' => ['Required.'],
        ]))])->get(route('fulfillment'))->getContent();
        $this->assertSame(['Required.'], $this->flashErrors($html));
    }

    /** Every label's `for` names an element on the page, and every `aria-describedby` id exists. */
    private function assertLabelsMatch(string $html, string $page): void
    {
        $x = $this->xpath($html);
        $ids = [];
        foreach ($x->query('//*[@id]') as $el) {
            $ids[$el->getAttribute('id')] = true;
        }
        $labels = $x->query('//label[@for]');
        foreach ($labels as $label) {
            $this->assertArrayHasKey($label->getAttribute('for'), $ids, "{$page}: label for=\"{$label->getAttribute('for')}\" ({$label->textContent}) has no control");
        }
        foreach ($x->query('//*[@aria-describedby]') as $el) {
            foreach (preg_split('/\s+/', trim($el->getAttribute('aria-describedby'))) as $id) {
                $this->assertArrayHasKey($id, $ids, "{$page}: aria-describedby=\"{$id}\" names nothing");
            }
        }
    }

    public function test_every_label_matches_a_control(): void
    {
        foreach (['spx', 'lbc'] as $courier) {
            CourierRegistry::fake($courier, FakeCourier::make($courier));
            CourierAccount::factory()->create(['courier' => $courier, 'label' => strtoupper($courier), 'is_default' => true, 'default_slot' => $courier]);
        }
        $this->savedPickup();

        $html = $this->get('/shipments/new')->assertOk()->getContent();
        $this->assertLabelsMatch($html, 'add');
        // The two labels the slug used to break.
        $this->assertStringContainsString('for="f-parcel-weight-kg"', $html);
        $this->assertStringContainsString('for="f-parcel-category"', $html);
        $this->assertLabelsMatch($this->failedForm(), 'add with errors');

        foreach ([route('addresses.customers.create'), route('addresses.pickup.create'), route('settings.edit', ['tab' => 'general']),
            route('account'), route('accounts.show', 'quadx'), route('accounts.show', 'spx'), route('accounts.show', 'lbc'),
            route('developers.index', ['tab' => 'keys', 'create' => 1])] as $url) {
            $this->assertLabelsMatch($this->get($url)->assertOk()->getContent(), $url);
        }

        $s = Shipment::factory()->create(['state' => 'failed', 'source' => 'manual', 'client_app_id' => null, 'courier_account_id' => $this->account->id, 'courier' => 'quadx']);
        $this->assertLabelsMatch($this->get(route('shipments.edit', $s->public_id))->assertOk()->getContent(), 'edit');
    }

    public function test_sections_run_courier_pickup_customer_parcel_payment_reference(): void
    {
        $html = $this->get('/shipments/new')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/id="sec-courier".*id="sec-pickup".*id="sec-customer".*id="sec-parcel".*id="sec-payment".*id="sec-reference"/s', $html);
        // Item, Packaging and the delivery note are in Parcel; the note left Customer.
        $parcel = substr($html, strpos($html, 'id="sec-parcel"'), strpos($html, 'id="sec-payment"') - strpos($html, 'id="sec-parcel"'));
        $this->assertMatchesRegularExpression('/id="f-item".*Packaging.*id="f-note"/s', $parcel);
        $customer = substr($html, strpos($html, 'id="sec-customer"'), strpos($html, 'id="sec-parcel"') - strpos($html, 'id="sec-customer"'));
        $this->assertStringNotContainsString('id="f-note"', $customer);
        // No headingless panel is left.
        $this->assertSame(0, preg_match('/<section class="x-panel sh-sec">/', $html));
    }

    public function test_province_city_and_barangay_are_marked_required_and_the_phone_names_its_label(): void
    {
        $x = $this->xpath($this->get('/shipments/new')->getContent());

        foreach (['ph-recipient-state' => 'Province', 'ph-recipient-city' => 'City or municipality', 'ph-recipient-barangay' => 'Barangay'] as $for => $text) {
            $label = $x->query("//label[@for='{$for}']")->item(0);
            $this->assertStringContainsString($text, $label->textContent);
            $this->assertSame(1, $x->query("//label[@for='{$for}']/span[@class='fm-req']")->length, $for);
            $this->assertStringContainsString('(required)', $label->textContent);
        }
        // WCAG 2.5.3: the accessible name starts with the visible label.
        $this->assertSame('Phone, +63', $x->query("//*[@id='f-recipient-phone']")->item(0)->getAttribute('aria-label'));
    }

    public function test_the_phone_shows_grouped_and_posts_any_spacing(): void
    {
        $html = $this->failedForm(['recipient' => ['phone' => '09175555555']]);
        $this->assertMatchesRegularExpression('/id="f-recipient-phone"[^>]*value="917 555 5555"/', $html);

        $this->fake->booksAs('QX-1');
        $this->post('/shipments', $this->form(['recipient' => ['phone' => '917 555 5555']]))->assertSessionHasNoErrors();
        $this->assertSame('09175555555', Shipment::query()->sole()->recipient['phone']);
    }

    public function test_summary_rows_and_live_bindings(): void
    {
        $html = $this->failedForm(['recipient' => ['first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'city' => 'Makati', 'state' => 'Metro Manila'], 'parcel' => ['type' => 'box']]);
        $summary = substr($html, strpos($html, 'id="sec-summary"'));

        $this->assertMatchesRegularExpression('#<dt>Courier</dt>.*<dt>To</dt>.*<dt>Item</dt>.*<dt>Packaging</dt>.*<dt>Value</dt>.*<dt>Payment</dt>.*<dt>Insured</dt>#s', $summary);
        $this->assertStringNotContainsString('<dt>Total</dt>', $summary);
        $this->assertMatchesRegularExpression('#x-text="toName">Juan Dela Cruz</span>#', $summary);
        $this->assertMatchesRegularExpression('#x-text="toPlace"[^>]*>Makati, Metro Manila</small>#', $summary);
        $this->assertMatchesRegularExpression('#x-text="packagingText">Box</dd>#', $summary);
        $this->assertMatchesRegularExpression('#x-text="total">₱1,250.00</dd>#', $summary);
        $this->assertMatchesRegularExpression('#x-text="method === \'cod\' \? \'COD\' : \'Prepaid\'">COD</dd>#', $summary);
    }

    public function test_save_draft_creates_a_draft_without_calling_the_courier(): void
    {
        $html = $this->get('/shipments/new')->getContent();
        $this->assertMatchesRegularExpression('#<button type="submit"[^>]*name="draft" value="1"[^>]*>Save draft</button>#', $html);
        $this->assertMatchesRegularExpression('#Save draft</button>\s*<button type="submit"\s+class="x-btn x-btn--primary[^"]*"[^>]*data-book[^>]*>Book shipment</button>#', $html);

        $body = $this->form(['draft' => '1']);
        $res = $this->post('/shipments', $body);

        $s = Shipment::query()->sole();
        $res->assertRedirect(route('shipments.show', $s->public_id))->assertSessionHas('status', 'Saved');
        $this->assertSame('draft', $s->state);
        $this->assertSame('manual', $s->source);
        $this->assertSame([], $this->fake->callsTo('createShipment'));
        $this->assertSame([], $this->fake->callsTo('quoteFee'), 'Save draft never asks the courier');
        $this->assertSame('draft', AuditEntry::query()->where('action', 'shipment.created')->sole()->changes['state']);
        $this->assertTrue($s->isEditable());

        // The same render cannot save it twice.
        $this->post('/shipments', $body)->assertRedirect(route('shipments.show', $s->public_id));
        $this->assertSame(1, Shipment::query()->count());
    }

    public function test_save_draft_validates_like_book(): void
    {
        $this->post('/shipments', $this->form(['draft' => '1', 'item' => '', 'recipient' => ['first_name' => '']]))
            ->assertSessionHasErrors(['item', 'recipient.first_name']);
        $this->assertSame(0, Shipment::query()->count());
        $this->assertSame([], $this->fake->callsTo('createShipment'));
    }

    public function test_a_picked_customer_comes_back_as_the_picked_box_after_an_error(): void
    {
        $c = Customer::query()->create(['phone' => '09171234567', 'name' => 'Juan Saved', 'shipments_count' => 1, 'last_shipped_at' => now(),
            'address' => ['first_name' => 'Juan', 'last_name' => 'Saved', 'phone' => '09171234567', 'address_1' => '99 Saved St', 'city' => 'Makati', 'state' => 'Metro Manila']]);

        $html = $this->failedForm(['customer_id' => $c->public_id, 'recipient' => ['first_name' => 'Juan']]);
        $x = $this->xpath($html);

        $box = $x->query('//div[contains(@class, "sh-picked")]')->item(0);
        $this->assertNotNull($box);
        $this->assertFalse($box->hasAttribute('x-cloak'));
        $this->assertSame('Juan Saved', trim($x->query('//*[contains(@class, "sh-picked__name")]')->item(0)->textContent));
        $this->assertSame('0917 123 4567 · Makati, Metro Manila', trim($x->query('//*[contains(@class, "sh-picked__meta")]')->item(0)->textContent));
        $this->assertSame(1, $x->query('//button[@aria-label="Clear customer"]')->length);
        $this->assertTrue($x->query('//div[contains(@class, "sh-find")]')->item(0)->hasAttribute('x-cloak'));

        // Without a pick the search shows and the box waits hidden.
        $x = $this->xpath($this->get('/shipments/new')->getContent());
        $this->assertTrue($x->query('//div[contains(@class, "sh-picked")]')->item(0)->hasAttribute('x-cloak'));
    }
}
