<?php

namespace Tests\Feature\Couriers;

use App\Couriers\LiveBookingBlocked;
use App\Couriers\LiveGuard;
use App\Models\CourierAccount;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LiveGuardTest extends TestCase
{
    use RefreshDatabase;

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
}
