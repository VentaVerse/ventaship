<?php

namespace Tests\Concerns;

use App\Couriers\AccountSettings;
use App\Couriers\CourierOrder;
use App\Couriers\CourierRegistry;
use App\Couriers\Lbc\LbcClient;
use App\Couriers\Lbc\LbcFulfillment;
use App\Couriers\PickupPoint;
use App\Couriers\TrackingNumbers\TrackingNumberSeriesService;
use App\Models\CourierAccount;
use App\Models\Shipment;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Shared setup for the LBC tests: an LBC account with neutral settings and a tracking number block, a pickup point,
 * a shipment, and faked LBC answers. LBC has no test system, so every answer is faked.
 */
trait LbcFixtures
{
    protected const SERIES_FIRST = 10000000000001;

    protected const SERIES_LAST = 10000000000010;

    protected const ACCOUNT_NO = '1000000000000001';

    /** Wednesday; the first pickup date is the Thursday after. */
    protected const NOW = '2026-11-04 09:00:00';

    protected const FIRST_PICKUP = '2026-11-05';

    protected CourierAccount $account;

    /** The clock on a Wednesday morning in Manila and a complete account with a block. */
    protected function setUpLbc(): void
    {
        $this->travelTo(CarbonImmutable::parse(self::NOW, 'Asia/Manila'));
        $this->account = $this->lbcAccount();
    }

    /** A sandbox LBC account with every setting stored and (by default) a block of numbers. */
    protected function lbcAccount(array $attributes = [], bool $withSeries = true): CourierAccount
    {
        $account = CourierAccount::factory()->create($attributes + [
            'courier' => 'lbc',
            'mode' => 'sandbox',
        ]);

        $settings = AccountSettings::for($account);
        $settings->write('lbc_client_code', 'DEMO');
        $settings->write('lbc_shipper_account_no', self::ACCOUNT_NO);
        $settings->writeSecret('lbc_sandbox_common_key', 'common-key');
        $settings->writeSecret('lbc_sandbox_airwaybill_key', 'waybill-key');
        $settings->writeSecret('lbc_sandbox_pickup_key', 'pickup-key');
        $settings->writeSecret('lbc_sandbox_track_key', 'track-key');

        if ($withSeries) {
            app(TrackingNumberSeriesService::class)->addSeries($account, self::SERIES_FIRST, self::SERIES_LAST);
        }

        return $account;
    }

    protected function lbc(?CourierAccount $account = null): LbcFulfillment
    {
        $courier = CourierRegistry::for($account ?? $this->account->fresh());
        $this->assertInstanceOf(LbcFulfillment::class, $courier);

        return $courier;
    }

    protected function pickupPoint(array $overrides = []): PickupPoint
    {
        return PickupPoint::fromArray($overrides + [
            'contact_name' => 'Demo Store',
            'contact_phone' => '09170000001',
            'address_1' => '1 Sample Street',
            'barangay' => 'Kapitolyo',
            'city' => 'Pasig City',
            'state' => 'Metro Manila',
            'postcode' => '1603',
            'country' => 'PH',
        ]);
    }

    /** A stored shipment for the LBC account, prepaid by default. */
    protected function shipment(array $recipient = [], array $attributes = []): Shipment
    {
        return Shipment::factory()->create($attributes + [
            'courier_account_id' => $this->account->id,
            'courier' => 'lbc',
            'courier_ref' => 'VS' . str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
            'payment_method' => 'prepaid',
            'recipient' => $recipient + [
                'first_name' => 'Juan',
                'last_name' => 'Dela Cruz',
                'phone' => '09170000000',
                'address_1' => '2 Sample Avenue',
                'barangay' => 'Poblacion',
                'city' => 'Makati City',
                'state' => 'Metro Manila',
                'postcode' => '1210',
                'country' => 'PH',
            ],
            'items' => [['name' => 'Demo Item', 'quantity' => 1, 'price' => 500]],
            'total' => 500,
        ]);
    }

    protected function order(array $recipient = [], array $attributes = []): CourierOrder
    {
        return CourierOrder::fromApi($this->shipment($recipient, $attributes));
    }

    protected function codOrder(float $total, array $recipient = []): CourierOrder
    {
        return $this->order($recipient, ['payment_method' => 'cod', 'cod_amount' => $total, 'total' => $total]);
    }

    /** What a booking sends for LBC, by default a box of 1.2 kg on the first pickup date. */
    protected function bookingOptions(array $overrides = []): array
    {
        return array_merge([
            'pickup' => $this->pickupPoint(),
            'service' => 'REGULAR',
            'parcel' => '16',
            'shipping_payment' => 'account',
            'weight_kg' => '1.2',
            'length_cm' => '20',
            'width_cm' => '15',
            'height_cm' => '10',
            'pickup_slot' => self::FIRST_PICKUP,
        ], $overrides);
    }

    /** Writes a successful booking onto its shipment, as the shipment creator will. */
    protected function recordBooking(Shipment $shipment, array $result): Shipment
    {
        $shipment->forceFill([
            'state' => 'booked',
            'tracking_number' => $result['tracking_number'],
            'courier_status' => $result['status'],
            'meta' => $result['meta'],
        ])->save();

        return $shipment->fresh();
    }

    /** LBC's address search and serviceability answers, faked. */
    protected function fakeLookups(string $deliveryStatus = 'Serviceable', ?string $category = 'METRO MANILA AREA', array $more = []): void
    {
        Http::fake(array_merge([
            '*/camsbackend/cities/search*' => function ($request) {
                $term = strtoupper((string) ($request->data()['searchTerm'] ?? ''));

                return Http::response(match (true) {
                    str_contains($term, 'PASIG') => [['cityId' => 1381200000, 'cityName' => 'CITY OF PASIG', 'provinceId' => 1300000000, 'provinceName' => 'NATIONAL CAPITAL REGION NCR']],
                    str_contains($term, 'MAKATI') => [['cityId' => 1380300000, 'cityName' => 'CITY OF MAKATI', 'provinceId' => 1300000000, 'provinceName' => 'NATIONAL CAPITAL REGION NCR']],
                    default => [],
                });
            },
            '*/camsbackend/barangays/search*' => function ($request) {
                $city = (int) ($request->data()['cityId'] ?? 0);

                return Http::response(match ($city) {
                    1381200000 => [['barangayId' => 1381200009, 'barangayName' => 'KAPITOLYO', 'cityId' => 1381200000, 'cityName' => 'CITY OF PASIG', 'provinceName' => 'NATIONAL CAPITAL REGION NCR', 'fullLocationName' => 'NATIONAL CAPITAL REGION NCR, CITY OF PASIG, KAPITOLYO']],
                    1380300000 => [['barangayId' => 1380300020, 'barangayName' => 'POBLACION', 'cityId' => 1380300000, 'cityName' => 'CITY OF MAKATI', 'provinceName' => 'NATIONAL CAPITAL REGION NCR', 'fullLocationName' => 'NATIONAL CAPITAL REGION NCR, CITY OF MAKATI, POBLACION']],
                    default => [],
                });
            },
            '*/camsbackend/validate/validateServicableArea' => function ($request) use ($deliveryStatus, $category) {
                $psgc = (string) ($request->data()['psgc_code'] ?? '');
                $status = $psgc === '1380300020' ? $deliveryStatus : 'Serviceable';
                $serviceable = $status === 'Serviceable';

                return Http::response([
                    'status' => $status,
                    'psgc_code' => $serviceable ? $psgc : null,
                    'rate_details' => ['rate' => $serviceable ? 250 : 0, 'category' => $serviceable ? $category : null, 'remarks' => null],
                ]);
            },
        ], $more));
    }

    /** LBC's address answers plus a pickup endpoint that saves and echoes the number. */
    protected function fakeLbc(?callable $pickup = null, array $more = [], string $deliveryStatus = 'Serviceable'): void
    {
        $this->fakeLookups($deliveryStatus, 'METRO MANILA AREA', array_merge([
            '*' . LbcClient::PICKUP_PATH => $pickup ?? fn (Request $request) => Http::response(
                self::pickupAccepted((string) simplexml_load_string($request->body())->TrackingNo)
            ),
        ], $more));
    }

    protected static function pickupAccepted(string $trackingNo): string
    {
        return '<?xml version="1.0" encoding="utf-8"?><ReturnEntity xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<IsSuccess>true</IsSuccess><MessageList><ResponseMessageEntity><Code>100</Code>'
            . '<Message>Successfully saved. 11/05/2026 9:28:50 AM</Message><TrackingNo>' . $trackingNo . '</TrackingNo>'
            . '</ResponseMessageEntity></MessageList></ReturnEntity>';
    }

    protected static function pickupRejected(string $code, string $message): string
    {
        return '<?xml version="1.0" encoding="utf-8"?><ReturnEntity>'
            . '<IsSuccess>false</IsSuccess><MessageList><ResponseMessageEntity><Code>' . $code . '</Code>'
            . '<Message>' . $message . '</Message><TrackingNo></TrackingNo>'
            . '</ResponseMessageEntity></MessageList></ReturnEntity>';
    }

    protected static function isPickupRequest(Request $request): bool
    {
        return str_contains($request->url(), LbcClient::PICKUP_PATH);
    }

    protected function pickupRequestCount(): int
    {
        return Http::recorded(fn (Request $request) => self::isPickupRequest($request))->count();
    }

    /** The one pickup request sent, as its fields. */
    protected function sentPickupFields(): array
    {
        $sent = Http::recorded(fn (Request $request) => self::isPickupRequest($request))->values();
        $this->assertCount(1, $sent, 'Exactly one pickup request goes to LBC.');

        $xml = simplexml_load_string($sent[0][0]->body());
        $fields = [];
        foreach ($xml->children() as $node) {
            $fields[$node->getName()] = (string) $node;
        }

        return $fields;
    }
}
