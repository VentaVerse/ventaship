<?php

namespace Tests\Feature\Couriers;

use App\Couriers\AccountSettings;
use App\Couriers\Lbc\LbcFulfillment;
use App\Couriers\Lbc\LbcPackaging;
use App\Couriers\Support\ServiceabilityVerdict;
use App\Couriers\TrackingNumbers\TrackingNumberSeries;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\LbcFixtures;
use Tests\TestCase;

/**
 * LBC's answer to "will you carry this?" before anything is booked, the rate it quotes as an estimate, and the
 * booking options it offers.
 */
class LbcServiceabilityAndOptionsTest extends TestCase
{
    use LbcFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLbc();
    }

    public function test_a_served_barangay_is_serviceable_and_asked_by_its_psgc_code(): void
    {
        $this->fakeLbc();

        $verdict = $this->lbc()->checkServiceability($this->order(), $this->pickupPoint());

        $this->assertSame(ServiceabilityVerdict::SERVICEABLE, $verdict->status);
        $this->assertFalse($verdict->odz, "LBC's own ODZ flag is its serviceability answer");
        $validate = Http::recorded(fn (Request $r) => str_contains($r->url(), 'validateServicableArea'))->values();
        $this->assertSame(['psgc_code' => 1380300020], $validate[0][0]->data());
        $this->assertSame('common-key', $validate[0][0]->header('lbcOAkey')[0]);
    }

    public function test_an_unserved_barangay_is_refused_with_lbcs_reason(): void
    {
        $this->fakeLbc(deliveryStatus: 'Not Serviceable');

        $verdict = $this->lbc()->checkServiceability($this->order(), $this->pickupPoint());

        $this->assertSame(ServiceabilityVerdict::NOT_SERVICEABLE, $verdict->status);
        $this->assertSame(ServiceabilityVerdict::KIND_DESTINATION, $verdict->kind);
        $this->assertSame('Not Serviceable', $verdict->reason);
        $this->assertTrue($verdict->odz);
    }

    public function test_an_address_lbc_cannot_find_is_not_a_refusal(): void
    {
        $this->fakeLbc();

        $lost = $this->lbc()->checkServiceability($this->order(['barangay' => 'Nowhere']), $this->pickupPoint());

        $this->assertSame(ServiceabilityVerdict::UNKNOWN, $lost->status);
        $this->assertNull($lost->odz);
        $this->assertStringContainsString('found no barangay matching "Nowhere"', (string) $lost->reason);
    }

    public function test_an_lbc_that_does_not_answer_is_could_not_check(): void
    {
        Http::fake(['*' => fn () => throw new ConnectionException('timed out')]);

        $silent = $this->lbc()->checkServiceability($this->order(), $this->pickupPoint());

        $this->assertSame(ServiceabilityVerdict::UNKNOWN, $silent->status);
        $this->assertSame(ServiceabilityVerdict::KIND_UNREACHABLE, $silent->kind);
    }

    public function test_the_address_search_is_remembered_but_coverage_is_asked_every_time(): void
    {
        $this->fakeLbc();
        $lbc = $this->lbc();
        $lbc->checkServiceability($this->order(), $this->pickupPoint());
        $lbc->checkServiceability($this->order(), $this->pickupPoint());

        $this->assertCount(1, Http::recorded(fn (Request $r) => str_contains($r->url(), 'cities/search')));
        $this->assertCount(2, Http::recorded(fn (Request $r) => str_contains($r->url(), 'validateServicableArea')));
    }

    public function test_the_rate_is_an_estimate_only(): void
    {
        $this->fakeLbc();

        $fee = $this->lbc()->estimateFee($this->order(), []);
        $this->assertSame(250.0, $fee->amount);
        $this->assertSame('PHP', $fee->currency);
        $this->assertSame('₱250.00 (LBC estimate)', $fee->label);
        $this->assertFalse($fee->odz);

        $this->assertNull($this->lbc()->estimateFee($this->order(['barangay' => 'Nowhere']), []));
    }

    public function test_the_parcel_list_is_lbcs_table_with_codes(): void
    {
        $options = $this->lbc()->getParcelOptions();
        $codes = array_column($options, 'value');

        $this->assertSame('16', $codes[0], 'The store\'s own box comes first.');
        $this->assertSame(['16', '165', '6', '164', '63', '7', '64', '45', '8', '62', '3', '61', '9', '2', '4', '79', '80'], $codes);
        $this->assertSame('Kilobox Slim', $options[3]['label']);
        foreach ($codes as $code) {
            $this->assertTrue(LbcPackaging::exists((int) $code));
        }
        $this->assertSame(['CARGO', 'KILOBOX MINI'], [LbcPackaging::productLine(165), LbcPackaging::productName(165)]);
        $this->assertSame(['DOCUMENTS', 'X POUCH'], [LbcPackaging::productLine(9), LbcPackaging::productName(9)]);
    }

    public function test_service_and_payment_lists_are_never_empty_and_drop_off_is_disabled(): void
    {
        $lbc = $this->lbc();

        $this->assertSame([
            ['value' => 'REGULAR', 'label' => 'Rider pickup'],
            ['value' => 'DROPOFF', 'label' => 'Drop off at an LBC branch', 'disabled' => true],
        ], $lbc->getServiceTypes());
        $this->assertSame([['value' => 'account', 'label' => 'Charged to my LBC account']], $lbc->getShippingPaymentOptions());
    }

    public function test_declared_value_rules(): void
    {
        $order = $this->order([], ['total' => 320]);

        $this->assertSame(0.0, LbcFulfillment::declaredValueFor($order, false, false));
        $this->assertSame(500.0, LbcFulfillment::declaredValueFor($order, true, false));
        $this->assertSame(500.0, LbcFulfillment::declaredValueFor($order, false, true), 'COD is insured even when not ticked.');
        $this->assertSame(1999.5, LbcFulfillment::declaredValueFor($this->order([], ['total' => 1999.5]), false, true));
        $cod = $this->order([], ['payment_method' => 'cod', 'total' => 320, 'cod_amount' => 290]);
        $this->assertSame(290.0, LbcFulfillment::codAmountFor($cod, true), 'The rider collects cod_amount, not the total.');
        $this->assertSame(0.0, LbcFulfillment::codAmountFor($cod, false));
        $this->assertSame(0.0, LbcFulfillment::codAmountFor($order, true), 'A prepaid shipment collects nothing.');
    }

    public function test_the_buyer_payment_is_the_shipments_and_a_contradiction_is_refused(): void
    {
        $this->fakeLbc();
        $cod = $this->codOrder(500);
        $prepaid = $this->order();

        $this->assertTrue(LbcFulfillment::isCod($cod, []));
        $this->assertFalse(LbcFulfillment::isCod($prepaid, []));

        $said = $this->lbc()->createShipment($prepaid, $this->bookingOptions(['buyer_payment' => 'cod']));
        $this->assertFalse($said['success']);
        $this->assertStringContainsString('the shipment is prepaid', $said['error']);

        $said = $this->lbc()->createShipment($cod, $this->bookingOptions(['buyer_payment' => 'prepaid']));
        $this->assertStringContainsString('the shipment is cash on delivery', $said['error']);

        $noAmount = $this->order([], ['payment_method' => 'cod', 'cod_amount' => null, 'total' => 500]);
        $this->assertStringContainsString('needs a cod_amount', $this->lbc()->createShipment($noAmount, $this->bookingOptions())['error']);

        Http::assertNothingSent();
    }

    public function test_missing_settings_keep_lbc_off_and_are_named(): void
    {
        AccountSettings::for($this->account)->write('lbc_shipper_account_no', null);
        TrackingNumberSeries::query()->delete();

        $lbc = $this->lbc();
        $this->assertFalse($lbc->isEnabled());
        $this->assertSame(['Shipper Account No', LbcFulfillment::NO_TRACKING_SERIES], $lbc->configurationIssues());

        $this->assertFalse($lbc->createShipment($this->order(), $this->bookingOptions())['success']);
        Http::assertNothingSent();
    }

    public function test_no_keys_at_all_is_named_once(): void
    {
        $account = \App\Models\CourierAccount::factory()->create(['courier' => 'lbc', 'mode' => 'production']);

        $this->assertSame(
            [LbcFulfillment::NO_KEYS_AT_ALL, 'Client code', 'Shipper Account No', LbcFulfillment::NO_TRACKING_SERIES],
            $this->lbc($account)->configurationIssues(),
        );
    }

    public function test_the_schema_has_no_enabled_toggle_or_series_keys_and_marks_keys_secret(): void
    {
        $keys = array_column($this->lbc()->settingsSchema(), 'key');

        $this->assertNotContains('lbc_enabled', $keys);
        $this->assertNotContains('lbc_tracking_series_start', $keys);
        $this->assertNotContains('lbc_tracking_series_end', $keys);
        foreach (['common', 'airwaybill', 'pickup', 'track'] as $api) {
            $field = collect($this->lbc()->settingsSchema())->firstWhere('key', "lbc_production_{$api}_key");
            $this->assertTrue($field['encrypted']);
        }
    }

    public function test_province_codes_decide_the_fallback_area_before_names(): void
    {
        // No category in the answer, so the area falls back to the region. A Cebu PSGC code wins over a name that
        // would say Metro Manila; a name alone is read from the bundled province list.
        $this->fakeLookups('Serviceable', null);
        $resolver = new \App\Couriers\Lbc\LbcAreaResolver(new \App\Couriers\Lbc\LbcClient(AccountSettings::for($this->account)));

        $byCode = $resolver->resolve('Nowhere', 'Nowhere City', 'Metro Manila', '072200000');
        $this->assertSame('VIS', $byCode->areaCode);

        $byName = $resolver->resolve('Nowhere', 'Nowhere City', 'Cebu');
        $this->assertSame('VIS', $byName->areaCode);

        $unknown = $resolver->resolve('Nowhere', 'Nowhere City', 'Atlantis');
        $this->assertNull($unknown->areaCode);
    }

    public function test_only_an_https_gateway_is_used_for_the_keys(): void
    {
        $settings = AccountSettings::for($this->account);

        $this->assertSame(LbcFulfillment::DEFAULT_GATEWAY_URL, LbcFulfillment::gatewayOf($settings));

        foreach (['http://lbc.example.test/', 'ftp://lbc.example.test/', 'https://user:pw@lbc.example.test/', 'not a url'] as $unsafe) {
            $settings->write('lbc_gateway_url', $unsafe);
            $this->assertSame(LbcFulfillment::DEFAULT_GATEWAY_URL, LbcFulfillment::gatewayOf($settings), $unsafe);
        }

        $settings->write('lbc_gateway_url', 'https://gateway.lbc.example.test/v2');
        $this->assertSame('https://gateway.lbc.example.test/v2/', LbcFulfillment::gatewayOf($settings));
    }
}
