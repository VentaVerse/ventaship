<?php

namespace Tests\Feature\Couriers;

use Carbon\CarbonImmutable;
use App\Couriers\Lbc\LbcAccount;
use App\Couriers\Lbc\LbcArea;
use App\Couriers\Lbc\LbcException;
use App\Couriers\Lbc\LbcParcel;
use App\Couriers\Lbc\LbcPickupRequestBuilder;
use App\Couriers\Lbc\LbcWaybillBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\LbcFixtures;
use Tests\TestCase;

/**
 * The pickup request against the structure of LBC's own sample payload
 * (Pickup Request API, section V.B) and the field table beside it.
 */
class LbcPickupRequestBuilderTest extends TestCase
{
    use LbcFixtures;
    use RefreshDatabase;

    /** Element order of LBC's sample payload, without the retired AppKey node. */
    private const SAMPLE_ORDER = [
        'ShipmentMode', 'Origin', 'TrackingNo', 'TransactionDate', 'ODZ', 'ShipperAccountNo', 'Shipper',
        'ShipperStBldg', 'ShipperBrgy', 'ShipperCityMuncipality', 'ShipperProvince', 'ShipperContactNumber',
        'ShipperSendSMS', 'ShipperMobileNumber', 'ProductLine', 'ServiceMode', 'CODAmount', 'PreferredDate',
        'Consignee', 'ConsigneeStBldg', 'ConsigneeBrgy', 'ConsigneeCityMuncipality', 'ConsigneeProvince',
        'ConsigneeContactNumber', 'ConsigneeSendSMS', 'ConsigneeMobileNumber', 'Quantity', 'PKG', 'ACTWTkgs',
        'LengthCM', 'WidthCM', 'HeightCM', 'VolWTcbm', 'CBM', 'ChargeableWT', 'DeclaredValue', 'Description',
        'Commodity', 'ForCrating', 'AttachmentNameOne', 'ReferenceNoOne', 'AttachmentNameTwo', 'ReferenceNoTwo',
        'AttachmentNameThree', 'ReferenceNoThree', 'AttachmentNameFour', 'ReferenceNoFour', 'DestinationCode', 'Client',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLbc();
    }

    private function build(array $overrides = []): array
    {
        $order = $this->order($overrides['order'] ?? [], ['courier_ref' => 'VS000123']);

        return (new LbcPickupRequestBuilder())->fields(
            $order,
            $overrides['from'] ?? $this->pickupPoint(),
            $overrides['parcel'] ?? new LbcParcel(16, 1, 20, 15, 10, 500),
            $this->area('KAPITOLYO', 'CITY OF PASIG'),
            $this->area('POBLACION', 'CITY OF MAKATI'),
            new LbcAccount('DEMO', self::ACCOUNT_NO),
            self::SERIES_FIRST,
            CarbonImmutable::parse('2026-11-05 01:30:00', 'UTC'),
            CarbonImmutable::parse('2026-11-09 10:00:00', 'Asia/Manila'),
            $overrides['mode'] ?? LbcPickupRequestBuilder::MODE_REGULAR,
        );
    }

    private function area(string $barangay, string $city): LbcArea
    {
        return (new LbcArea($barangay, $city, 'Metro Manila'))
            ->withLocation('1380300020', $barangay, $city, 'NATIONAL CAPITAL REGION NCR', '')
            ->withServiceability(true, 'Serviceable', 'METRO MANILA AREA', 250)
            ->withAreaCode('MMA', LbcArea::FROM_SERVICEABILITY);
    }

    public function test_fields_follow_the_sample_payload_order(): void
    {
        $this->assertSame(self::SAMPLE_ORDER, array_keys($this->build()));
    }

    public function test_xml_has_the_sample_root_namespaces_and_one_element_per_field(): void
    {
        $fields = $this->build();
        $xml = simplexml_load_string((new LbcPickupRequestBuilder())->toXml($fields));

        $this->assertSame('PickupRequestEntity', $xml->getName());
        $namespaces = $xml->getDocNamespaces();
        $this->assertSame('http://www.w3.org/2001/XMLSchema', $namespaces['xsd']);
        $this->assertSame('http://www.w3.org/2001/XMLSchema-instance', $namespaces['xsi']);

        $names = [];
        foreach ($xml->children() as $child) {
            $names[] = $child->getName();
        }
        $this->assertSame(self::SAMPLE_ORDER, $names);
        $this->assertSame((string) self::SERIES_FIRST, (string) $xml->TrackingNo);
    }

    public function test_fixed_values_match_the_field_table(): void
    {
        $fields = $this->build();

        $this->assertSame('1', $fields['ShipmentMode']);
        $this->assertSame('2', $fields['ProductLine']);
        $this->assertSame('8', $fields['ServiceMode']);
        $this->assertSame('1', $fields['Quantity']);
        $this->assertSame('16', $fields['PKG']);
        $this->assertSame('1', $fields['Commodity']);
        $this->assertSame('1', $fields['ConsigneeSendSMS']);
        $this->assertSame('0', $fields['ShipperSendSMS']);
        $this->assertSame('0', $fields['ForCrating']);
        $this->assertSame('0', $fields['CBM']);
        $this->assertSame('false', $fields['ODZ']);
        foreach (['AttachmentNameThree', 'ReferenceNoThree', 'AttachmentNameFour', 'ReferenceNoFour'] as $unused) {
            $this->assertSame('0', $fields[$unused]);
        }
    }

    public function test_order_pickup_address_and_account_map_to_their_fields(): void
    {
        $fields = $this->build();

        $this->assertSame('MMA', $fields['Origin']);
        $this->assertSame('MMA', $fields['DestinationCode']);
        $this->assertSame(self::ACCOUNT_NO, $fields['ShipperAccountNo']);
        $this->assertSame('DEMO', $fields['Client']);
        $this->assertSame('Demo Store', $fields['Shipper']);
        $this->assertSame('1 Sample Street', $fields['ShipperStBldg']);
        $this->assertSame('KAPITOLYO', $fields['ShipperBrgy']);
        $this->assertSame('09170000001', $fields['ShipperContactNumber']);
        $this->assertSame('09170000001', $fields['ShipperMobileNumber']);
        $this->assertSame('Juan Dela Cruz', $fields['Consignee']);
        $this->assertSame('2 Sample Avenue', $fields['ConsigneeStBldg']);
        $this->assertSame('POBLACION', $fields['ConsigneeBrgy']);
        $this->assertSame('CITY OF MAKATI', $fields['ConsigneeCityMuncipality']);
        $this->assertSame('NATIONAL CAPITAL REGION NCR', $fields['ConsigneeProvince']);
        $this->assertSame('09170000000', $fields['ConsigneeContactNumber']);
        $this->assertSame('1 x Demo Item', $fields['Description']);
        $this->assertSame('ORDERNO', $fields['AttachmentNameOne']);
        $this->assertSame('VS000123', $fields['ReferenceNoOne']);
    }

    public function test_dates_are_manila_time_in_lbc_format(): void
    {
        $fields = $this->build();

        // 01:30 UTC is 09:30 in Manila.
        $this->assertSame('11/05/2026 09:30:00', $fields['TransactionDate']);
        $this->assertSame('11/09/2026 10:00:00', $fields['PreferredDate']);
    }

    public function test_weights_follow_the_formula(): void
    {
        $fields = $this->build();

        // 20 x 15 x 10 / 3500 = 0.857142...
        $this->assertSame('0.8571', $fields['VolWTcbm']);
        $this->assertSame('1', $fields['ACTWTkgs']);
        $this->assertSame('1', $fields['ChargeableWT']);
        $this->assertSame('500', $fields['DeclaredValue']);
        $this->assertSame('0', $fields['CODAmount']);

        $bulky = $this->build(['parcel' => new LbcParcel(16, 1, 40, 30, 20, 750)]);
        $this->assertSame('6.8571', $bulky['VolWTcbm']);
        $this->assertSame('6.86', $bulky['ChargeableWT']);
    }

    public function test_pickup_mode_defaults_to_regular_and_can_be_drop_off(): void
    {
        $this->assertSame(['MODE', 'REGULAR'], [$this->build()['AttachmentNameTwo'], $this->build()['ReferenceNoTwo']]);
        $this->assertSame('DROPOFF', $this->build(['mode' => LbcPickupRequestBuilder::MODE_DROP_OFF])['ReferenceNoTwo']);

        $this->expectException(LbcException::class);
        $this->build(['mode' => 'EXPRESS']);
    }

    public function test_values_are_escaped_in_xml(): void
    {
        $fields = $this->build(['order' => ['address_1' => 'Unit 3 <A> & B']]);
        $xml = (new LbcPickupRequestBuilder())->toXml($fields);

        $this->assertStringContainsString('<ConsigneeStBldg>Unit 3 &lt;A&gt; &amp; B</ConsigneeStBldg>', $xml);
        $this->assertSame('Unit 3 <A> & B', (string) simplexml_load_string($xml)->ConsigneeStBldg);
    }

    public function test_masking_hides_the_account_number_and_phones(): void
    {
        $masked = LbcPickupRequestBuilder::mask($this->build());
        $xml = (new LbcPickupRequestBuilder())->toXml($masked);

        $this->assertStringNotContainsString(self::ACCOUNT_NO, $xml);
        $this->assertStringNotContainsString('09170000000', $xml);
        $this->assertStringNotContainsString('09170000001', $xml);
        $this->assertSame('0917*****00', $masked['ConsigneeContactNumber']);
        $this->assertSame('Juan Dela Cruz', $masked['Consignee']);
    }

    public function test_a_missing_receiver_phone_refuses(): void
    {
        $this->expectException(LbcException::class);
        $this->build(['order' => ['phone' => null]]);
    }

    public function test_the_reference_is_cut_at_lbcs_50_characters(): void
    {
        $fields = (new LbcPickupRequestBuilder())->fields(
            $this->order([], ['courier_ref' => str_repeat('A', 49).'BCDEFGHIJ']),
            $this->pickupPoint(),
            new LbcParcel(16, 1, 20, 15, 10, 500),
            $this->area('KAPITOLYO', 'CITY OF PASIG'),
            $this->area('POBLACION', 'CITY OF MAKATI'),
            new LbcAccount('DEMO', self::ACCOUNT_NO),
            self::SERIES_FIRST,
            CarbonImmutable::parse('2026-11-05 01:30:00', 'UTC'),
            CarbonImmutable::parse('2026-11-09 10:00:00', 'Asia/Manila'),
        );

        $this->assertSame(str_repeat('A', 49).'B', $fields['ReferenceNoOne']);
    }

    public function test_a_shipment_without_a_courier_reference_refuses(): void
    {
        $this->expectException(LbcException::class);
        (new LbcPickupRequestBuilder())->fields(
            $this->order([], ['courier_ref' => null]),
            $this->pickupPoint(),
            new LbcParcel(16, 1, 20, 15, 10, 500),
            $this->area('KAPITOLYO', 'CITY OF PASIG'),
            $this->area('POBLACION', 'CITY OF MAKATI'),
            new LbcAccount('DEMO', self::ACCOUNT_NO),
            self::SERIES_FIRST,
            CarbonImmutable::parse('2026-11-05 01:30:00', 'UTC'),
            CarbonImmutable::parse('2026-11-09 10:00:00', 'Asia/Manila'),
        );
    }

    public function test_declared_value_below_the_minimum_refuses(): void
    {
        $this->expectException(LbcException::class);
        new LbcParcel(16, 1, 20, 15, 10, 499);
    }

    public function test_waybill_label_carries_the_same_facts(): void
    {
        $pickup = $this->build();
        $label = (new LbcWaybillBuilder())->label($pickup, 'Demo Store');

        $this->assertSame($pickup['TrackingNo'], $label['TrackingNo']);
        $this->assertSame($pickup['TrackingNo'], $label['Barcode1D']);
        $this->assertSame('NCR', $label['Origin']);
        $this->assertSame('NCR', $label['Destination']);
        $this->assertSame('20x15x10', $label['LWH']);
        $this->assertSame('CARGO', $label['Product']);
        $this->assertSame('BOX', $label['ProductName']);
        $this->assertSame('None', $label['DeliveryRequirement']);
        $this->assertSame('2 Sample Avenue, POBLACION, CITY OF MAKATI, NATIONAL CAPITAL REGION NCR', $label['ConsigneeAddress']);
        $this->assertSame($pickup['TransactionDate'], $label['TransactionDate']);
    }
}
