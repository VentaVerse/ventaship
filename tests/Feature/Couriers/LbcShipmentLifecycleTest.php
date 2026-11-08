<?php

namespace Tests\Feature\Couriers;

use App\Couriers\Lbc\LbcClient;
use App\Couriers\Lbc\LbcFulfillment;
use App\Couriers\Lbc\LbcTrackingStatus;
use App\Couriers\TrackingNumbers\TrackingNumber;
use App\Models\Shipment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\LbcFixtures;
use Tests\TestCase;

/**
 * What happens to an LBC booking after it is made: the waybill printed from what was sent, tracking read from
 * Track and Trace (many events share StatusId 0, so the text decides), the poll interval, and the cancel that can
 * only ever be local.
 */
class LbcShipmentLifecycleTest extends TestCase
{
    use LbcFixtures, RefreshDatabase;

    private const PDF = "%PDF-1.4\n% demo waybill\n";

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLbc();
        Storage::fake('local');
    }

    /** A booked shipment, through the real booking path, with tracking and waybill answers faked too. */
    private function booked(array $tracking = [], array $attributes = []): Shipment
    {
        $this->fakeLbc(null, [
            '*' . LbcClient::WAYBILL_PATH => Http::response(['Rendered_Byte' => base64_encode(self::PDF)]),
            '*' . LbcClient::TRACK_PATH => fn () => Http::response(self::trackAnswer($tracking)),
        ]);

        $shipment = $this->shipment([], $attributes);
        $result = $this->lbc()->createShipment(\App\Couriers\CourierOrder::fromApi($shipment), $this->bookingOptions());
        $this->assertTrue($result['success']);

        return $this->recordBooking($shipment, $result);
    }

    /** A Track and Trace SOAP answer; no events means "No Record Found". */
    private static function trackAnswer(array $events): string
    {
        if ($events === []) {
            return '<?xml version="1.0" encoding="utf-8"?><soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body>'
                . '<LBCTrackAndTraceResponse xmlns="http://tempuri.org/"><LBCTrackAndTraceResult><TrackingStatus>'
                . '<StatusCode>201</StatusCode><StatusMessage>NO RECORD FOUND</StatusMessage>'
                . '</TrackingStatus></LBCTrackAndTraceResult></LBCTrackAndTraceResponse></soap:Body></soap:Envelope>';
        }

        $history = '';
        foreach ($events as [$id, $date, $time, $text]) {
            $history .= "<TrackingHistory><StatusId>{$id}</StatusId><DatePosted>{$date}</DatePosted>"
                . '<StatusandLocation>' . htmlspecialchars($text, ENT_XML1) . "</StatusandLocation><DatePostedTime>{$time}</DatePostedTime>"
                . '<Remarks /></TrackingHistory>';
        }

        return '<?xml version="1.0" encoding="utf-8"?><soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body>'
            . '<LBCTrackAndTraceResponse xmlns="http://tempuri.org/"><LBCTrackAndTraceResult><TrackingStatus>'
            . '<StatusCode>0001</StatusCode><StatusMessage>SUCCESS!</StatusMessage>'
            . '<TrackingDetails><TrackingDetails><TrackingNo>' . self::SERIES_FIRST . '</TrackingNo></TrackingDetails></TrackingDetails>'
            . '<TrackingHistory>' . $history . '</TrackingHistory>'
            . '</TrackingStatus></LBCTrackAndTraceResult></LBCTrackAndTraceResponse></soap:Body></soap:Envelope>';
    }

    private function waybillRequests(): array
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), LbcClient::WAYBILL_PATH))->values()->all();
    }

    // --- Waybill ------------------------------------------------------------------

    public function test_the_waybill_is_made_from_the_request_that_was_sent_and_kept(): void
    {
        $shipment = $this->booked([], ['courier_note' => 'Call before delivery']);

        // The recipient changes after booking; the label must not.
        $shipment->update(['recipient' => ['address_1' => '99 Changed Street'] + $shipment->recipient]);

        $result = $this->lbc()->printLabel((string) self::SERIES_FIRST, 'pdf');

        $this->assertTrue($result['success']);
        $this->assertSame('labels/lbc/' . self::SERIES_FIRST . '.pdf', $result['path']);
        Storage::disk('local')->assertExists($result['path']);
        $this->assertSame(self::PDF, Storage::disk('local')->get($result['path']));

        $label = $this->waybillRequests()[0][0]->data();
        $this->assertSame((string) self::SERIES_FIRST, $label['TrackingNo']);
        $this->assertStringStartsWith('2 Sample Avenue', $label['ConsigneeAddress']);
        $this->assertSame('Call before delivery', $label['SpecialInstructions']);
        $this->assertSame('BOX', $label['ProductName']);
        $this->assertSame('20x15x10', $label['LWH']);
        $this->assertSame('None', $label['DeliveryRequirement']);
        $this->assertSame('waybill-key', $this->waybillRequests()[0][0]->header('lbcOAkey')[0]);

        // Printed again: served from the stored file, LBC is not asked twice.
        $this->assertTrue($this->lbc()->printLabel((string) self::SERIES_FIRST, 'pdf')['success']);
        $this->assertCount(1, $this->waybillRequests());
    }

    public function test_a_cod_waybill_names_the_amount_to_collect(): void
    {
        $this->booked([], ['payment_method' => 'cod', 'cod_amount' => 1350, 'total' => 1350]);

        $this->lbc()->printLabel((string) self::SERIES_FIRST, 'pdf');

        $label = $this->waybillRequests()[0][0]->data();
        $this->assertSame('COD', $label['DeliveryRequirement']);
        $this->assertSame('1350', $label['Amount']);
        $this->assertSame('1350', $label['DeclaredValue']);
    }

    public function test_a_waybill_without_a_stored_request_is_refused_without_asking_lbc(): void
    {
        $shipment = $this->booked();
        $shipment->update(['meta' => ['mode' => 'test']]);

        $result = $this->lbc()->printLabel((string) self::SERIES_FIRST, 'pdf');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('no stored LBC request', $result['error']);
        $this->assertCount(0, $this->waybillRequests());
    }

    public function test_a_waybill_is_only_found_on_the_same_account(): void
    {
        $this->booked();
        $other = $this->lbcAccount(['label' => 'Other shop'], withSeries: false);

        $result = $this->lbc($other)->printLabel((string) self::SERIES_FIRST, 'pdf');

        $this->assertFalse($result['success']);
        $this->assertCount(0, $this->waybillRequests());
    }

    public function test_a_cancelled_booking_has_no_waybill(): void
    {
        $shipment = $this->booked();
        $shipment->update(['courier_status' => 'cancelled']);

        $this->assertFalse($this->lbc()->printLabel((string) self::SERIES_FIRST, 'pdf')['success']);
        $this->assertCount(0, $this->waybillRequests());
    }

    // --- Tracking -----------------------------------------------------------------

    public function test_no_record_found_leaves_the_booking_as_it_is(): void
    {
        $this->booked();

        $result = $this->lbc()->getTracking((string) self::SERIES_FIRST);

        $this->assertTrue($result['success']);
        $this->assertNull($result['status']);
        $this->assertSame('track-key', Http::recorded(fn (Request $r) => str_contains($r->url(), LbcClient::TRACK_PATH))->values()[0][0]->header('lbcOAkey')[0]);
    }

    public function test_events_sharing_status_id_zero_are_told_apart_by_their_text(): void
    {
        $this->booked([
            [0, '11/05/2026', '02:10:00 PM', 'PICKED UP BY LBC'],
            [0, '11/05/2026', '06:00:00 PM', 'Shipment has been received at LBC CARGO FACILITY.'],
            [0, '11/06/2026', '08:00:00 AM', 'Shipment is en route to MAKATI DELIVERY TEAM. Next update for this shipment will come within 1 day/s.'],
            [8002, '11/06/2026', '09:30:00 AM', 'Please expect delivery within the day.'],
        ]);

        $result = $this->lbc()->getTracking((string) self::SERIES_FIRST);

        $this->assertTrue($result['success']);
        $this->assertSame(LbcTrackingStatus::OUT_FOR_DELIVERY, $result['status']);
        $timeline = $result['timeline'];
        $this->assertSame(['picked_up', 'received', 'forwarded', 'out_for_delivery'], array_column($timeline, 'status'));
        $this->assertSame('Shipment has been received at LBC CARGO FACILITY.', $timeline[1]['detail']);
        $this->assertSame(\Carbon\CarbonImmutable::parse('2026-11-05 14:10:00', 'Asia/Manila')->getTimestamp(), $timeline[0]['at']);
    }

    public function test_status_text_is_read_safely(): void
    {
        $this->assertSame(LbcTrackingStatus::COD_REMITTED, LbcTrackingStatus::forEvent(0, 'Deposited to Account of Shipper'));
        $this->assertSame(LbcTrackingStatus::READY_FOR_PICKUP, LbcTrackingStatus::forEvent('0', 'Shipment is now ready for pick up at LBC MAKATI'));
        $this->assertSame(LbcTrackingStatus::DELIVERED, LbcTrackingStatus::forEvent(1, 'Received by Juan Dela Cruz on 11/06/2026'));
        $this->assertSame(LbcTrackingStatus::RETURNED, LbcTrackingStatus::forEvent(51, 'RTS'));
        $this->assertSame(LbcTrackingStatus::FAILED_ATTEMPT, LbcTrackingStatus::forEvent(6, 'CONSIGNEE OUT'));
        $this->assertSame(LbcTrackingStatus::DELAYED, LbcTrackingStatus::forEvent(19, 'LATE ARRIVAL'));
        // An id we do not know is read by its text, and never as a delivery when the text says it was not delivered.
        $this->assertSame(LbcTrackingStatus::DELAYED, LbcTrackingStatus::forEvent(777, 'Your shipment was not delivered due to an unforeseen event.'));
        $this->assertSame(LbcTrackingStatus::IN_TRANSIT, LbcTrackingStatus::forEvent(777, 'Something new'));

        $this->assertSame('delivered', LbcTrackingStatus::phase('cod_remitted')['phase']);
        $this->assertSame('failed', LbcTrackingStatus::phase('needs_attention')['phase']);
        $this->assertSame('returning', LbcTrackingStatus::phase('returned')['phase']);
        $this->assertSame('to_handover', LbcTrackingStatus::phase('unconfirmed')['phase']);
        $this->assertSame('in_transit', LbcTrackingStatus::phase('delayed')['phase']);
    }

    public function test_the_poll_waits_an_hour_before_pickup_and_half_an_hour_once_moving_and_stops_when_final(): void
    {
        $lbc = $this->lbc();

        $this->assertSame(60, $lbc->pollIntervalMinutes(LbcTrackingStatus::BOOKED));
        $this->assertSame(60, $lbc->pollIntervalMinutes(LbcTrackingStatus::UNCONFIRMED));
        $this->assertSame(60, $lbc->pollIntervalMinutes(null));
        $this->assertSame(30, $lbc->pollIntervalMinutes(LbcTrackingStatus::PICKED_UP));
        $this->assertContains(LbcTrackingStatus::DELIVERED, $lbc->terminalStatuses());
        $this->assertContains(LbcTrackingStatus::COD_REMITTED, $lbc->terminalStatuses());
        $this->assertNotContains(LbcTrackingStatus::FAILED_ATTEMPT, $lbc->terminalStatuses(), 'LBC re-delivers.');
    }

    // --- Cancel ---------------------------------------------------------------------

    public function test_cancel_is_local_keeps_the_number_spent_and_says_to_call_lbc(): void
    {
        $this->booked();
        $before = Http::recorded()->count();

        $result = $this->lbc()->cancelShipment((string) self::SERIES_FIRST);

        $this->assertTrue($result['success']);
        $this->assertSame($before, Http::recorded()->count(), 'Nothing is sent to LBC on cancel.');
        $this->assertSame('LBC has no cancel API. Call LBC to void this booking.', $this->lbc()->localCancelNotice());
        $this->assertSame(TrackingNumber::SPENT, TrackingNumber::where('number', self::SERIES_FIRST)->value('status'));

        // Booking again takes the next number, never the cancelled one.
        $again = $this->lbc()->createShipment($this->order(), $this->bookingOptions());
        $this->assertSame((string) (self::SERIES_FIRST + 1), $again['tracking_number']);
    }

    public function test_a_parcel_already_picked_up_cannot_be_cancelled(): void
    {
        $shipment = $this->booked();
        $shipment->update(['courier_status' => LbcTrackingStatus::PICKED_UP]);

        $result = $this->lbc()->cancelShipment((string) self::SERIES_FIRST);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Call LBC to recall it', $result['error']);
        $this->assertNotContains(LbcTrackingStatus::PICKED_UP, $this->lbc()->cancelableStatuses());
    }

    public function test_resolve_pending_creation_is_always_null(): void
    {
        $this->assertNull($this->lbc()->resolvePendingCreation($this->order()));
        $this->assertInstanceOf(LbcFulfillment::class, $this->lbc());
    }
}
