<?php

namespace Tests\Feature\Couriers;

use App\Couriers\AccountSettings;
use App\Couriers\CourierOrder;
use App\Couriers\CourierRegistry;
use App\Couriers\Lbc\LbcClient;
use App\Couriers\LiveBookingBlocked;
use App\Couriers\LiveGuard;
use App\Couriers\PickupPoint;
use App\Couriers\Spx\SpxFulfillment;
use App\Couriers\TrackingNumbers\TrackingNumber;
use App\Models\CourierAccount;
use App\Models\Shipment;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsCourierAccounts;
use Tests\Concerns\LbcFixtures;
use Tests\TestCase;

/**
 * The live-booking locks, at the guard itself and through the real adapters: LBC has no test system and no cancel,
 * so a pickup or waybill request needs every lock; SPX needs them only for a production account's create and
 * cancel; QuadX never does. Every blocked case also proves nothing left for the courier.
 */
class LiveGuardTest extends TestCase
{
    use BuildsCourierAccounts, LbcFixtures, RefreshDatabase;

    public function test_quadx_never_guarded(): void
    {
        config(['couriers.lbc_live' => false, 'couriers.spx_live' => false]);
        $account = CourierAccount::factory()->create(['courier' => 'quadx', 'mode' => 'production', 'allow_live_booking' => false]);

        foreach (['create', 'cancel', 'pickup', 'airwaybill', 'track', 'label'] as $operation) {
            LiveGuard::assertAllowed($account, $operation);
        }

        $this->addToAssertionCount(1);
    }

    public function test_lbc_allowed_with_every_lock_strictly_true(): void
    {
        $account = $this->lbcWithAllLocks();

        LiveGuard::assertAllowed($account, 'pickup');
        LiveGuard::assertAllowed($account, 'airwaybill');

        $this->addToAssertionCount(1);
    }

    /** @return array<string, array{mixed}> */
    public static function truthyNonTrue(): array
    {
        return ['string "true"' => ['true'], 'string "yes"' => ['yes'], 'string "1"' => ['1'], 'int 1' => [1]];
    }

    #[DataProvider('truthyNonTrue')]
    public function test_setting_lock_must_be_strictly_true(mixed $value): void
    {
        $account = $this->lbcWithAllLocks();
        app(Settings::class)->set('safety.allow_live_lbc', $value);

        $this->expectException(LiveBookingBlocked::class);
        LiveGuard::assertAllowed($account, 'pickup');
    }

    #[DataProvider('truthyNonTrue')]
    public function test_config_lock_must_be_strictly_true(mixed $value): void
    {
        $account = $this->lbcWithAllLocks();
        config(['couriers.lbc_live' => $value]);

        $this->expectException(LiveBookingBlocked::class);
        LiveGuard::assertAllowed($account, 'airwaybill');
    }

    private function lbcWithAllLocks(): CourierAccount
    {
        config(['couriers.lbc_live' => true]);
        app(Settings::class)->set('safety.allow_live_lbc', true);

        return CourierAccount::factory()->create(['courier' => 'lbc', 'mode' => 'production', 'allow_live_booking' => true]);
    }

    // --- LBC, through the adapter ---------------------------------------------------

    /** Every lock on, a complete account with a block, LBC faked: the booking that the cases below take apart. */
    private function readyLbc(array $attributes = []): CourierAccount
    {
        $this->travelTo(\Carbon\CarbonImmutable::parse(self::NOW, 'Asia/Manila'));
        $this->unlockLiveLbc();
        $this->account = $this->lbcAccount($attributes);
        $this->fakeLbc();

        return $this->account;
    }

    private function assertLbcBookingBlocked(CourierAccount $account, string $missing): void
    {
        try {
            CourierRegistry::for($account->fresh())->createShipment($this->order(), $this->bookingOptions());
            $this->fail('A live LBC booking went through with a lock off.');
        } catch (LiveBookingBlocked $e) {
            $this->assertSame('lbc', $e->courier);
            $this->assertSame('pickup', $e->operation);
            $this->assertContains($missing, $e->missing);
        }

        Http::assertNothingSent();
        $this->assertSame(0, TrackingNumber::count(), 'No tracking number is taken for a blocked booking.');
    }

    public function test_lbc_blocked_when_env_off(): void
    {
        $account = $this->readyLbc();
        config(['couriers.lbc_live' => false]);

        $this->assertLbcBookingBlocked($account, 'COURIERS_LBC_LIVE');
    }

    public function test_lbc_blocked_when_setting_off(): void
    {
        $account = $this->readyLbc();
        app(Settings::class)->set('safety.allow_live_lbc', false);

        $this->assertLbcBookingBlocked($account, 'setting safety.allow_live_lbc');
    }

    public function test_lbc_blocked_when_account_latch_off(): void
    {
        $account = $this->readyLbc(['allow_live_booking' => false]);

        $this->assertLbcBookingBlocked($account, 'account allow_live_booking');
    }

    public function test_lbc_blocked_when_the_account_is_not_in_production_mode(): void
    {
        $account = $this->readyLbc(['mode' => 'staging']);

        $this->assertLbcBookingBlocked($account, 'account in production mode');
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function strictlyFalseAccountLocks(): array
    {
        return [
            'latch null' => [['allow_live_booking' => null], 'account allow_live_booking'],
            'latch 0' => [['allow_live_booking' => 0], 'account allow_live_booking'],
            'mode Production' => [['mode' => 'Production'], 'account in production mode'],
            'mode prod' => [['mode' => 'prod'], 'account in production mode'],
            'mode live' => [['mode' => 'live'], 'account in production mode'],
        ];
    }

    #[DataProvider('strictlyFalseAccountLocks')]
    public function test_account_locks_must_be_exactly_on(array $attributes, string $missing): void
    {
        $account = $this->lbcWithAllLocks();
        $account->forceFill($attributes);

        try {
            LiveGuard::assertAllowed($account, 'pickup');
            $this->fail('The guard let a not-quite-on account lock through.');
        } catch (LiveBookingBlocked $e) {
            $this->assertContains($missing, $e->missing);
        }
    }

    public function test_the_lbc_client_itself_refuses_before_sending(): void
    {
        $account = $this->readyLbc();
        config(['couriers.lbc_live' => false]);
        $client = new LbcClient(AccountSettings::for($account));

        foreach ([fn () => $client->insertPickupRequest('<PickupRequestEntity/>'), fn () => $client->generateWaybill(['TrackingNo' => '1'])] as $call) {
            try {
                $call();
                $this->fail('The LBC client sent a guarded request with a lock off.');
            } catch (LiveBookingBlocked) {
            }
        }

        Http::assertNothingSent();
    }

    public function test_a_blocked_lbc_waybill_is_not_requested(): void
    {
        $account = $this->readyLbc();
        $shipment = $this->shipment();
        $result = CourierRegistry::for($account)->createShipment(CourierOrder::fromApi($shipment), $this->bookingOptions());
        $this->recordBooking($shipment, $result);
        app(Settings::class)->set('safety.allow_live_lbc', false);
        $before = Http::recorded()->count();

        try {
            CourierRegistry::for($account->fresh())->printLabel($result['tracking_number'], 'pdf');
            $this->fail('A waybill was requested with a lock off.');
        } catch (LiveBookingBlocked $e) {
            $this->assertSame('airwaybill', $e->operation);
        }

        $this->assertSame($before, Http::recorded()->count());
    }

    public function test_lbc_allowed_only_with_all_locks_and_fake(): void
    {
        $account = $this->readyLbc();

        $result = CourierRegistry::for($account)->createShipment($this->order(), $this->bookingOptions());

        $this->assertTrue($result['success']);
        $this->assertSame(1, $this->pickupRequestCount());
    }

    // --- SPX ------------------------------------------------------------------------

    private function spxBooking(CourierAccount $account): array
    {
        $shipment = Shipment::factory()->create([
            'courier_account_id' => $account->id,
            'courier' => 'spx',
            'courier_ref' => 'VS000777',
            'recipient' => [
                'first_name' => 'Jane', 'last_name' => 'Doe', 'phone' => '09171234567', 'address_1' => '123 Mango St',
                'barangay' => 'Lahug (Pob.)', 'city' => 'Cebu City', 'state' => 'Cebu', 'postcode' => '6000', 'country' => 'PH',
            ],
        ]);

        $courier = new SpxFulfillment($account, AccountSettings::for($account), sleeper: fn (int $ms) => null);

        return [$courier, CourierOrder::fromApi($shipment), ['pickup' => PickupPoint::fromArray([
            'contact_name' => 'Warehouse Team', 'contact_phone' => '09171112222', 'address_1' => '456 Warehouse Ave',
            'barangay' => 'Bel-Air', 'city' => 'Makati', 'state' => 'Metro Manila', 'postcode' => '1200',
        ]), 'parcel' => 'small', 'weight_kg' => 1]];
    }

    private function fakeSpx(): void
    {
        Http::fake([
            '*batch_create_order*' => Http::response(['ret_code' => 0, 'message' => 'success.', 'data' => ['batch_no' => 1]]),
            '*get_order_create_result*' => Http::response(['ret_code' => 0, 'message' => 'success.', 'data' => [
                'batch_no' => 1, 'task_status' => 4, 'fail_count' => 0, 'fail_list' => [],
                'orders' => [['order_id' => 'VS000777', 'tracking_no' => 'SPEPH000000000777']],
            ]]),
            '*batch_cancel_order*' => Http::response(['ret_code' => 0, 'message' => 'success.', 'data' => ['tracking_no_list' => ['SPEPH000000000777'], 'fail_list' => []]]),
        ]);
    }

    public function test_spx_uat_needs_no_lock(): void
    {
        config(['couriers.spx_live' => false]);
        $this->fakeSpx();
        [$courier, $order, $opts] = $this->spxBooking($this->spxAccount(['mode' => 'staging', 'allow_live_booking' => false]));

        $this->assertTrue($courier->createShipment($order, $opts)['success']);
        $this->assertTrue($courier->cancelShipment('SPEPH000000000777')['success']);
        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://test-stable.spx.ph/'));
    }

    public function test_spx_production_create_blocked_without_locks(): void
    {
        $this->fakeSpx();
        [$courier, $order, $opts] = $this->spxBooking($this->spxAccount(['mode' => 'production', 'allow_live_booking' => false]));

        foreach ([fn () => $courier->createShipment($order, $opts), fn () => $courier->cancelShipment('SPEPH000000000777')] as $i => $call) {
            try {
                $call();
                $this->fail('A production SPX call went through with every lock off.');
            } catch (LiveBookingBlocked $e) {
                $this->assertSame('spx', $e->courier);
                $this->assertSame($i === 0 ? 'create' : 'cancel', $e->operation);
                $this->assertSame(['COURIERS_SPX_LIVE', 'setting safety.allow_live_spx', 'account allow_live_booking'], $e->missing);
            }
        }

        Http::assertNothingSent();
    }

    public function test_spx_production_is_guarded_by_each_lock_and_allowed_with_all(): void
    {
        $this->fakeSpx();
        $account = $this->spxAccount(['mode' => 'production', 'allow_live_booking' => true]);
        app(Settings::class)->set('safety.allow_live_spx', true);
        config(['couriers.spx_live' => false]);

        [$courier, $order, $opts] = $this->spxBooking($account);
        try {
            $courier->createShipment($order, $opts);
            $this->fail('A production SPX create went through with the env lock off.');
        } catch (LiveBookingBlocked $e) {
            $this->assertSame(['COURIERS_SPX_LIVE'], $e->missing);
        }
        Http::assertNothingSent();

        config(['couriers.spx_live' => true]);
        $this->assertTrue($courier->createShipment($order, $opts)['success']);
        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://spx.ph/open/api/v2/order/batch_create_order'));
    }
}
