<?php

namespace Tests\Feature\Couriers;

use App\Couriers\Lbc\LbcFulfillment;
use App\Couriers\Lbc\LbcTrackingStatus;
use App\Couriers\TrackingNumbers\TrackingNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\LbcFixtures;
use Tests\TestCase;

/**
 * An LBC booking from createShipment() to LBC's answer, with every LBC answer faked: the request LBC receives, what
 * the booking keeps, and what each of LBC's three answers does to the tracking number.
 */
class LbcBookingFlowTest extends TestCase
{
    use LbcFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLbc();
    }

    public function test_lbc_is_enabled_once_every_setting_is_present(): void
    {
        $lbc = $this->lbc();

        $this->assertSame([], $lbc->configurationIssues());
        $this->assertTrue($lbc->isEnabled());
    }

    public function test_a_suspended_account_is_not_enabled(): void
    {
        $this->account->update(['status' => 'suspended']);

        $this->assertFalse($this->lbc()->isEnabled());
        $this->assertFalse($this->lbc()->createShipment($this->order(), $this->bookingOptions())['success']);
        Http::assertNothingSent();
    }

    public function test_a_prepaid_booking_sends_one_request_and_returns_the_shipment(): void
    {
        $this->fakeLbc();
        $order = $this->order();

        $result = $this->lbc()->createShipment($order, $this->bookingOptions());

        $this->assertTrue($result['success']);
        $this->assertSame((string) self::SERIES_FIRST, $result['tracking_number']);
        $this->assertSame(LbcTrackingStatus::BOOKED, $result['status']);
        $this->assertNull($result['notice']);
        $this->assertNull($result['attention_reason']);

        $fields = $this->sentPickupFields();
        $this->assertSame((string) self::SERIES_FIRST, $fields['TrackingNo']);
        $this->assertSame('REGULAR', $fields['ReferenceNoTwo']);
        $this->assertSame('16', $fields['PKG']);
        $this->assertSame('1.2', $fields['ACTWTkgs']);
        $this->assertSame('0', $fields['CODAmount']);
        $this->assertSame('0', $fields['DeclaredValue'], 'Uninsured prepaid parcels declare 0.');
        $this->assertSame('11/05/2026 10:00:00', $fields['PreferredDate']);
        $this->assertSame($order->reference, $fields['ReferenceNoOne']);

        $sent = Http::recorded(fn (Request $r) => self::isPickupRequest($r))->values()[0][0];
        $this->assertSame('pickup-key', $sent->header('Ocp-Apim-Subscription-Key')[0]);

        $meta = $result['meta'];
        $this->assertSame(self::FIRST_PICKUP, $meta['pickup_date']);
        $this->assertFalse($meta['insured']);
        $this->assertSame((string) self::SERIES_FIRST, $meta['pickup_request']['TrackingNo']);
        $this->assertArrayNotHasKey('ShipperAccountNo', $meta['pickup_request'], 'The account number is not kept.');

        $this->assertSame(TrackingNumber::SPENT, TrackingNumber::where('number', self::SERIES_FIRST)->value('status'));
        $this->assertSame((int) $order->id, (int) TrackingNumber::where('number', self::SERIES_FIRST)->value('shipment_id'));
    }

    public function test_an_insured_prepaid_parcel_declares_the_order_total_with_the_floor(): void
    {
        $this->fakeLbc();
        $this->lbc()->createShipment($this->order([], ['total' => 1250]), $this->bookingOptions(['insure' => '1']));
        $this->assertSame('1250', $this->sentPickupFields()['DeclaredValue']);
    }

    public function test_an_insured_value_below_the_floor_is_raised_to_it(): void
    {
        $this->fakeLbc();
        $this->lbc()->createShipment($this->order([], ['total' => 300]), $this->bookingOptions(['insure' => '1']));
        $this->assertSame('500', $this->sentPickupFields()['DeclaredValue']);
    }

    public function test_cod_collects_the_order_total_and_is_always_insured(): void
    {
        $this->fakeLbc();

        // No insure option at all: COD insures regardless.
        $result = $this->lbc()->createShipment($this->codOrder(1350), $this->bookingOptions());
        $this->assertTrue($result['success']);

        $fields = $this->sentPickupFields();
        $this->assertSame('1350', $fields['CODAmount']);
        $this->assertSame('1350', $fields['DeclaredValue']);

        $this->assertTrue($result['meta']['insured']);
        $this->assertTrue($result['meta']['cod']);
        $this->assertSame('1350', $result['meta']['pickup_request']['CODAmount']);
    }

    public function test_cod_insurance_cannot_be_turned_off(): void
    {
        $this->fakeLbc();
        $this->lbc()->createShipment($this->codOrder(900), $this->bookingOptions(['insure' => '0']));
        $this->assertSame('900', $this->sentPickupFields()['DeclaredValue']);
    }

    public function test_a_definite_rejection_releases_the_number_and_books_nothing(): void
    {
        // Refused the first time, saved the second.
        $calls = 0;
        $this->fakeLbc(function (Request $request) use (&$calls) {
            return ++$calls === 1
                ? Http::response(self::pickupRejected('131', 'PKG is required'))
                : Http::response(self::pickupAccepted((string) simplexml_load_string($request->body())->TrackingNo));
        });
        $order = $this->order();

        $refused = $this->lbc()->createShipment($order, $this->bookingOptions());
        $this->assertFalse($refused['success']);
        $this->assertStringContainsString('LBC refused the booking', $refused['error']);
        $this->assertStringContainsString('code 131', $refused['error']);
        $this->assertStringContainsString(self::SERIES_FIRST . ' was not used', $refused['error']);
        $this->assertSame(TrackingNumber::RELEASED, TrackingNumber::where('number', self::SERIES_FIRST)->value('status'));

        // The released number is the next one taken.
        $booked = $this->lbc()->createShipment($order, $this->bookingOptions());
        $this->assertTrue($booked['success']);
        $this->assertSame((string) self::SERIES_FIRST, $booked['tracking_number']);
        $this->assertSame(2, $this->pickupRequestCount());
    }

    /**
     * An answer that may mean LBC saved the pickup: the number is spent, the booking is kept as unconfirmed, and the
     * caller is told to put it in front of a person. Nothing is retried.
     */
    public function test_an_unknown_answer_spends_the_number_and_needs_attention(): void
    {
        $this->fakeLbc(fn () => Http::response('Gateway timeout', 504));

        $result = $this->lbc()->createShipment($this->order(), $this->bookingOptions());

        $this->assertTrue($result['success']);
        $this->assertSame(LbcTrackingStatus::UNCONFIRMED, $result['status']);
        $this->assertStringContainsString('LBC did not confirm pickup request ' . self::SERIES_FIRST, $result['notice']);
        $this->assertStringContainsString('did not confirm pickup request ' . self::SERIES_FIRST, $result['attention_reason']);
        $this->assertSame(TrackingNumber::SPENT, TrackingNumber::where('number', self::SERIES_FIRST)->value('status'));
        $this->assertSame(1, $this->pickupRequestCount(), 'Never retried.');
    }

    public function test_an_unserviceable_address_is_refused_before_a_number_is_taken(): void
    {
        $this->fakeLbc(deliveryStatus: 'Not Serviceable');

        $result = $this->lbc()->createShipment($this->order(), $this->bookingOptions());

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('LBC does not serve the delivery address', $result['error']);
        $this->assertSame(0, $this->pickupRequestCount());
        $this->assertSame(0, TrackingNumber::count());
    }

    public function test_drop_off_is_refused_while_it_is_not_offered(): void
    {
        $this->fakeLbc();

        $result = $this->lbc()->createShipment($this->order(), $this->bookingOptions(['service' => 'DROPOFF']));

        $this->assertStringContainsString('Drop off at an LBC branch is not offered yet', $result['error']);
        Http::assertNothingSent();
        $this->assertSame(0, TrackingNumber::count());
    }

    public function test_a_booking_without_a_pickup_point_is_refused(): void
    {
        $this->fakeLbc();

        $result = $this->lbc()->createShipment($this->order(), $this->bookingOptions(['pickup' => null]));

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('pickup address', $result['error']);
        Http::assertNothingSent();
    }

    public function test_an_own_box_without_dimensions_is_refused_and_lbc_packaging_may_omit_them(): void
    {
        $this->fakeLbc();
        $order = $this->order();

        $refused = $this->lbc()->createShipment($order, $this->bookingOptions(['length_cm' => null, 'width_cm' => null, 'height_cm' => null]));
        $this->assertStringContainsString('needs its length, width and height', $refused['error']);
        Http::assertNothingSent();

        // A Kilobox Small without a size is sent with 0, for LBC to accept or refuse.
        $booked = $this->lbc()->createShipment($order, $this->bookingOptions(['parcel' => '6', 'length_cm' => null, 'width_cm' => null, 'height_cm' => null]));
        $this->assertTrue($booked['success']);
        $fields = $this->sentPickupFields();
        $this->assertSame(['6', '0', '0', '0', '0', '1.2'], [$fields['PKG'], $fields['LengthCM'], $fields['WidthCM'], $fields['HeightCM'], $fields['VolWTcbm'], $fields['ChargeableWT']]);
    }

    public function test_a_sunday_or_past_pickup_date_is_refused(): void
    {
        $this->fakeLbc();
        $order = $this->order();

        $this->assertStringContainsString('Sundays', $this->lbc()->createShipment($order, $this->bookingOptions(['pickup_slot' => '2026-11-08']))['error']);
        $this->assertStringContainsString('Nov 5, 2026 or later', $this->lbc()->createShipment($order, $this->bookingOptions(['pickup_slot' => '2026-11-04']))['error']);
        $this->assertStringContainsString('must be a date', $this->lbc()->createShipment($order, $this->bookingOptions(['pickup_slot' => 'tomorrow']))['error']);

        Http::assertNothingSent();
    }

    public function test_no_pickup_date_means_the_next_business_day(): void
    {
        // Saturday: the next business day skips Sunday.
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-07 15:00:00', 'Asia/Manila'));
        $this->fakeLbc();

        $this->assertTrue($this->lbc()->createShipment($this->order(), $this->bookingOptions(['pickup_slot' => null]))['success']);
        $this->assertSame('11/09/2026 10:00:00', $this->sentPickupFields()['PreferredDate']);
    }

    public function test_weight_comes_from_the_items_and_must_be_given_when_unknown(): void
    {
        $this->fakeLbc();

        $refused = $this->lbc()->createShipment($this->order(), $this->bookingOptions(['weight_kg' => null]));
        $this->assertStringContainsString('Enter the parcel weight', $refused['error']);
        Http::assertNothingSent();

        $weighed = $this->order([], ['items' => [['name' => 'Demo Item', 'quantity' => 2, 'price' => 250, 'weight_kg' => 0.75]]]);
        $this->assertTrue($this->lbc()->createShipment($weighed, $this->bookingOptions(['weight_kg' => null]))['success']);
        $this->assertSame('1.5', $this->sentPickupFields()['ACTWTkgs']);
    }

    public function test_the_booking_form_context_names_the_next_number_and_says_it_is_live(): void
    {
        $context = $this->lbc()->bookingFormContext();

        $this->assertSame((string) self::SERIES_FIRST, $context['nextTrackingNumber']);
        $this->assertSame(self::FIRST_PICKUP, $context['pickupDateMin']);
        $this->assertTrue($context['confirm']);
        $this->assertSame('Live', $this->lbc()->bookingModeLabel());
        Http::assertNothingSent();
    }

    public function test_the_series_belongs_to_the_account(): void
    {
        $this->fakeLbc();
        $other = $this->lbcAccount(['label' => 'Second shop'], withSeries: false);

        $this->assertContains(LbcFulfillment::NO_TRACKING_SERIES, $this->lbc($other)->configurationIssues());
        $this->assertFalse($this->lbc($other)->createShipment($this->order(), $this->bookingOptions())['success']);
        $this->assertSame(0, $this->pickupRequestCount());
    }

    public function test_cod_collects_the_cod_amount_not_the_total(): void
    {
        $this->fakeLbc();

        $order = $this->order([], ['payment_method' => 'cod', 'total' => 1500.00, 'cod_amount' => 1290.00]);
        $this->assertTrue($this->lbc()->createShipment($order, $this->bookingOptions())['success']);

        $fields = $this->sentPickupFields();
        $this->assertSame('1290', $fields['CODAmount']);
        $this->assertSame('1500', $fields['DeclaredValue'], 'The insured value is unchanged.');
    }

    /** The pickup request may have reached LBC before the connection broke: the number is LBC's now. */
    public function test_a_pickup_request_that_throws_in_transport_keeps_the_number_and_needs_attention(): void
    {
        $sent = 0;
        $this->fakeLbc(function () use (&$sent) {
            $sent++;

            throw new \Illuminate\Http\Client\ConnectionException('cURL error 28: Operation timed out');
        });

        $result = $this->lbc()->createShipment($this->order(), $this->bookingOptions());

        $this->assertTrue($result['success']);
        $this->assertSame(LbcTrackingStatus::UNCONFIRMED, $result['status']);
        $this->assertSame((string) self::SERIES_FIRST, $result['tracking_number']);
        $this->assertNotNull($result['attention_reason']);
        $this->assertStringContainsString('did not confirm pickup request ' . self::SERIES_FIRST, $result['attention_reason']);
        $this->assertSame(TrackingNumber::SPENT, TrackingNumber::where('number', self::SERIES_FIRST)->value('status'));
        $this->assertSame(0, TrackingNumber::where('status', TrackingNumber::RELEASED)->count());
        $this->assertSame(1, $sent, 'Sent once, never retried.');

        // The next booking never gets the number LBC may hold.
        $this->fakeLbc();
        $this->assertSame((string) (self::SERIES_FIRST + 1), $this->lbc()->createShipment($this->order(), $this->bookingOptions())['tracking_number']);
    }

    /** A fault after the request left (reading LBC's answer) is an unknown outcome, never a release. */
    public function test_a_fault_after_the_request_left_keeps_the_number_spent(): void
    {
        $this->fakeLbc();
        $account = $this->account->fresh();
        $client = new class(\App\Couriers\AccountSettings::for($account)) extends \App\Couriers\Lbc\LbcClient {
            public function insertPickupRequest(string $xml): \App\Couriers\Lbc\LbcPickupResult
            {
                parent::insertPickupRequest($xml);

                throw new \RuntimeException('The answer could not be parsed.');
            }
        };
        $lbc = new LbcFulfillment($account, \App\Couriers\AccountSettings::for($account), $client);

        $result = $lbc->createShipment($this->order(), $this->bookingOptions());

        $this->assertTrue($result['success']);
        $this->assertSame(LbcTrackingStatus::UNCONFIRMED, $result['status']);
        $this->assertNotNull($result['attention_reason']);
        $this->assertSame(TrackingNumber::SPENT, TrackingNumber::where('number', self::SERIES_FIRST)->value('status'));
        $this->assertSame(1, $this->pickupRequestCount());
    }

    /** A refusal the client raises before anything leaves (a key it cannot read): that number does go back. */
    public function test_a_refusal_before_sending_releases_the_number(): void
    {
        $this->fakeLbc();
        $account = $this->account->fresh();
        $client = new class(\App\Couriers\AccountSettings::for($account)) extends \App\Couriers\Lbc\LbcClient {
            public function insertPickupRequest(string $xml): \App\Couriers\Lbc\LbcPickupResult
            {
                throw new \App\Couriers\Lbc\LbcException('The LBC pickup key is not set.');
            }
        };
        $lbc = new LbcFulfillment($account, \App\Couriers\AccountSettings::for($account), $client);

        $result = $lbc->createShipment($this->order(), $this->bookingOptions());

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('key is not set', $result['error']);
        $this->assertSame(0, $this->pickupRequestCount());
        $this->assertSame(TrackingNumber::RELEASED, TrackingNumber::where('number', self::SERIES_FIRST)->value('status'));
    }

    public function test_the_insured_value_is_the_declared_value_not_the_total(): void
    {
        $this->fakeLbc();

        $order = $this->order([], ['total' => 1500.00, 'declared_value' => 800.00]);
        $this->assertTrue($this->lbc()->createShipment($order, $this->bookingOptions(['insure' => '1']))['success']);

        $this->assertSame('800', $this->sentPickupFields()['DeclaredValue']);
    }
}
