<?php

namespace Tests\Feature\Console;

use App\Couriers\TrackingNumbers\TrackingNumberSeries;
use App\Models\AuditEntry;
use App\Models\CourierAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LbcSeriesAddTest extends TestCase
{
    use RefreshDatabase;

    private function lbc(): CourierAccount
    {
        return CourierAccount::factory()->create(['courier' => 'lbc', 'mode' => 'production']);
    }

    public function test_adds_a_block_to_the_account(): void
    {
        $account = $this->lbc();

        $this->artisan('ventaship:lbc-series-add', ['account' => $account->public_id, 'first' => '10000000000001', 'last' => '10000000001000'])
            ->expectsOutputToContain('1000 numbers')
            ->assertSuccessful();

        $series = TrackingNumberSeries::query()->sole();
        $this->assertSame($account->id, (int) $series->courier_account_id);
        $this->assertSame('lbc', $series->courier);
        $this->assertSame(10000000000001, $series->first_number);
        $this->assertSame(10000000001000, $series->last_number);
        $this->assertNull($series->last_issued_number);
        $this->assertSame('tracking_series.added', AuditEntry::query()->latest('id')->value('action'));
        Http::assertNothingSent();
    }

    /** @return array<string, array{string, string}> */
    public static function overlapping(): array
    {
        return [
            'same block' => ['10000000000001', '10000000001000'],
            'inside' => ['10000000000500', '10000000000600'],
            'straddles the start' => ['9999999999000', '10000000000001'],
            'straddles the end' => ['10000000001000', '10000000002000'],
            'covers it' => ['9000000000000', '11000000000000'],
        ];
    }

    #[DataProvider('overlapping')]
    public function test_refuses_a_block_overlapping_any_lbc_account(string $first, string $last): void
    {
        $existing = $this->lbc();
        $this->artisan('ventaship:lbc-series-add', ['account' => $existing->public_id, 'first' => '10000000000001', 'last' => '10000000001000'])->assertSuccessful();

        foreach ([$existing, $this->lbc()] as $account) {
            $this->artisan('ventaship:lbc-series-add', ['account' => $account->public_id, 'first' => $first, 'last' => $last])
                ->expectsOutputToContain('overlaps')
                ->assertFailed();
        }

        $this->assertSame(1, TrackingNumberSeries::count());
    }

    public function test_an_adjacent_block_is_accepted(): void
    {
        $account = $this->lbc();
        $this->artisan('ventaship:lbc-series-add', ['account' => $account->public_id, 'first' => '10000000000001', 'last' => '10000000001000'])->assertSuccessful();
        $this->artisan('ventaship:lbc-series-add', ['account' => $this->lbc()->public_id, 'first' => '10000000001001', 'last' => '10000000002000'])->assertSuccessful();

        $this->assertSame(2, TrackingNumberSeries::count());
    }

    /** @return array<string, array{string, string}> */
    public static function badRanges(): array
    {
        return [
            'first above last' => ['10000000001000', '10000000000001'],
            'not digits' => ['1000000000000X', '10000000001000'],
            'negative' => ['-5', '10'],
            'zero' => ['0', '10'],
            'too long' => ['100000000000000000000', '100000000000000000001'],
        ];
    }

    #[DataProvider('badRanges')]
    public function test_refuses_a_range_that_is_not_a_block(string $first, string $last): void
    {
        $this->artisan('ventaship:lbc-series-add', ['account' => $this->lbc()->public_id, 'first' => $first, 'last' => $last])
            ->assertFailed();

        $this->assertSame(0, TrackingNumberSeries::count());
    }

    public function test_refuses_an_unknown_or_non_lbc_account(): void
    {
        $quadx = CourierAccount::factory()->create(['courier' => 'quadx']);

        $this->artisan('ventaship:lbc-series-add', ['account' => 'acc_nope', 'first' => '1', 'last' => '10'])->assertFailed();
        $this->artisan('ventaship:lbc-series-add', ['account' => $quadx->public_id, 'first' => '1', 'last' => '10'])->assertFailed();

        $this->assertSame(0, TrackingNumberSeries::count());
    }
}
