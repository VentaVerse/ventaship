<?php

namespace Tests\Feature\Couriers;

use App\Couriers\TrackingNumbers\TrackingNumber;
use App\Couriers\TrackingNumbers\TrackingNumberSeries;
use App\Couriers\TrackingNumbers\TrackingNumberSeriesException;
use App\Couriers\TrackingNumbers\TrackingNumberSeriesService;
use App\Models\CourierAccount;
use App\Models\Shipment;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Numbers from the blocks an account was assigned: in order, never outside them, never twice, and back in the pool
 * only when the courier definitely saved nothing.
 */
class TrackingNumberSeriesTest extends TestCase
{
    use RefreshDatabase;

    private const FIRST = 10000000000001;

    private const LAST = 10000000000005;

    private TrackingNumberSeriesService $series;

    private CourierAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->series = new TrackingNumberSeriesService();
        $this->account = CourierAccount::factory()->create(['courier' => 'lbc', 'mode' => 'production']);
        $this->series->addSeries($this->account, self::FIRST, self::LAST);
    }

    private function reserve(?CourierAccount $account = null): int
    {
        return $this->series->reserve($account ?? $this->account)->number;
    }

    public function test_numbers_are_issued_in_order_from_the_first(): void
    {
        $this->assertSame([self::FIRST, self::FIRST + 1, self::FIRST + 2], [$this->reserve(), $this->reserve(), $this->reserve()]);
        $this->assertSame(3, TrackingNumber::where('status', TrackingNumber::RESERVED)->count());
    }

    public function test_a_reservation_names_its_shipment(): void
    {
        $shipment = Shipment::factory()->create(['courier_account_id' => $this->account->id, 'courier' => 'lbc']);

        $issued = $this->series->reserve($this->account, $shipment->id);

        $this->assertSame($shipment->id, (int) TrackingNumber::whereKey($issued->id)->value('shipment_id'));
    }

    public function test_the_block_end_refuses(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $last = $this->reserve();
        }
        $this->assertSame(self::LAST, $last);

        try {
            $this->reserve();
            $this->fail('A number past the block end was issued.');
        } catch (TrackingNumberSeriesException $e) {
            $this->assertStringContainsString('used up', $e->getMessage());
        }

        $this->assertSame(5, TrackingNumber::count());
    }

    public function test_the_next_block_is_used_once_the_first_is_used_up(): void
    {
        $this->series->addSeries($this->account, 20000000000001, 20000000000002);

        for ($i = 0; $i < 5; $i++) {
            $this->reserve();
        }

        $this->assertSame(20000000000001, $this->reserve());
        $status = $this->series->status($this->account);
        $this->assertSame([7, 1, 20000000000002, true], [$status['size'], $status['remaining'], $status['next'], $status['near_end']]);
    }

    public function test_an_account_without_a_block_refuses(): void
    {
        $other = CourierAccount::factory()->create(['courier' => 'lbc']);

        $this->assertNull($this->series->status($other));

        $this->expectException(TrackingNumberSeriesException::class);
        $this->reserve($other);
    }

    public function test_it_warns_near_the_end(): void
    {
        Log::spy();

        $this->assertFalse($this->series->reserve($this->account)->nearEnd);
        $this->reserve();
        $this->reserve();
        $fourth = $this->series->reserve($this->account);

        // A five-number block warns with one number (20 percent) left.
        $this->assertSame(1, $fourth->remaining);
        $this->assertTrue($fourth->nearEnd);
        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, 'running out'))->atLeast()->once();
    }

    public function test_a_spent_number_is_never_issued_again(): void
    {
        $issued = $this->series->reserve($this->account);
        $this->series->spend($issued, 'Unknown: LBC did not answer');

        $this->assertSame(self::FIRST + 1, $this->reserve());
        $this->assertSame(TrackingNumber::SPENT, TrackingNumber::where('number', self::FIRST)->value('status'));
    }

    public function test_a_released_number_is_issued_next(): void
    {
        $first = $this->series->reserve($this->account);
        $second = $this->series->reserve($this->account);
        $this->series->spend($second, 'Accepted');
        $this->series->release($first, 'Rejected: code 107');

        $this->assertSame(self::FIRST, $this->series->peek($this->account)->number);
        $this->assertSame(self::FIRST, $this->reserve());
        $this->assertSame(self::FIRST + 2, $this->reserve());
    }

    public function test_a_number_settles_once(): void
    {
        $issued = $this->series->reserve($this->account);
        $this->series->spend($issued, 'Accepted');

        $this->expectException(TrackingNumberSeriesException::class);
        $this->series->release($issued, 'Rejected');
    }

    public function test_peek_writes_nothing(): void
    {
        $this->assertSame(self::FIRST, $this->series->peek($this->account)->number);
        $this->assertSame(self::FIRST, $this->series->peek($this->account)->number);

        $this->assertNull(TrackingNumberSeries::query()->value('last_issued_number'));
        $this->assertSame(0, TrackingNumber::count());
    }

    public function test_an_overlapping_block_refuses_on_any_account(): void
    {
        $other = CourierAccount::factory()->create(['courier' => 'lbc']);

        foreach ([[self::FIRST, self::LAST], [self::FIRST + 2, self::LAST + 10], [self::FIRST - 10, self::FIRST]] as [$first, $last]) {
            foreach ([$this->account, $other] as $account) {
                try {
                    $this->series->addSeries($account, $first, $last);
                    $this->fail("The block {$first}-{$last} overlaps and was accepted.");
                } catch (TrackingNumberSeriesException $e) {
                    $this->assertStringContainsString('overlaps', $e->getMessage());
                }
            }
        }

        $this->assertSame(1, TrackingNumberSeries::count());
    }

    public function test_a_block_needs_a_first_not_above_its_last(): void
    {
        $this->expectException(TrackingNumberSeriesException::class);
        $this->series->addSeries($this->account, 30000000000010, 30000000000001);
    }

    public function test_accounts_keep_separate_series(): void
    {
        $other = CourierAccount::factory()->create(['courier' => 'lbc']);
        $this->series->addSeries($other, 20000000000001, 20000000000005);

        $this->assertSame(self::FIRST, $this->reserve());
        $this->assertSame(20000000000001, $this->reserve($other));
        $this->assertSame(self::FIRST + 1, $this->reserve());
    }

    /**
     * Four processes claim from one block at once, through a database file they all share. Every number they got
     * must be different, and together they must be the first numbers of the block with no gap.
     */
    public function test_concurrent_claims_never_share_a_number(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is needed to run claims in parallel.');
        }

        $dir = sys_get_temp_dir() . '/tn-series-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $file = $dir . '/race.sqlite';
        touch($file);

        config(['database.connections.race' => [
            'driver' => 'sqlite',
            'database' => $file,
            'prefix' => '',
            'foreign_key_constraints' => false,
            'busy_timeout' => 10000,
            'journal_mode' => 'wal',
        ]]);

        $this->createSeriesTables('race');
        DB::purge('race');
        (new TrackingNumberSeriesService('race'))->addSeries($this->account, 20000000000001, 20000000001000);
        DB::purge('race');

        $workers = 4;
        $perWorker = 15;
        $pids = [];
        $account = $this->account;

        for ($w = 0; $w < $workers; $w++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                // Child: its own connection, its own results file, and no shutdown handlers from the parent run.
                DB::purge('race');
                $service = new TrackingNumberSeriesService('race');
                $got = [];
                for ($i = 0; $i < $perWorker; $i++) {
                    for ($try = 0; $try < 50; $try++) {
                        try {
                            $got[] = $service->reserve($account)->number;
                            break;
                        } catch (\Throwable) {
                            usleep(random_int(1000, 5000));
                        }
                    }
                }
                file_put_contents("{$dir}/worker-{$w}.json", json_encode($got));
                posix_kill(posix_getpid(), SIGKILL);
            }
            $pids[] = $pid;
        }

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        $all = [];
        for ($w = 0; $w < $workers; $w++) {
            $all = array_merge($all, json_decode((string) @file_get_contents("{$dir}/worker-{$w}.json"), true) ?? []);
        }

        DB::purge('race');
        $rows = DB::connection('race')->table('tracking_numbers')->count();
        $lastIssued = DB::connection('race')->table('tracking_series')->value('last_issued_number');
        DB::purge('race');

        array_map('unlink', glob("{$dir}/*"));
        rmdir($dir);

        $this->assertCount($workers * $perWorker, $all, 'Some claims never succeeded.');
        $this->assertSame(count($all), count(array_unique($all)), 'Two claims got the same number.');
        sort($all);
        $this->assertSame(range(20000000000001, 20000000000000 + count($all)), $all);
        $this->assertSame(count($all), $rows);
        $this->assertSame(20000000000000 + count($all), (int) $lastIssued);
    }

    /** The two tables, shaped as the migration creates them (without foreign keys), on another connection. */
    private function createSeriesTables(string $connection): void
    {
        $schema = Schema::connection($connection);

        $schema->create('tracking_series', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('courier_account_id');
            $table->string('courier', 16);
            $table->unsignedBigInteger('first_number');
            $table->unsignedBigInteger('last_number');
            $table->unsignedBigInteger('last_issued_number')->nullable();
            $table->unsignedInteger('low_water_mark')->default(200);
            $table->timestamps();
            $table->unique(['courier', 'first_number']);
        });

        $schema->create('tracking_numbers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('series_id');
            $table->unsignedBigInteger('number');
            $table->string('status', 16)->default('reserved');
            $table->unsignedBigInteger('shipment_id')->nullable();
            $table->string('outcome')->nullable();
            $table->timestamps();
            $table->unique(['series_id', 'number']);
        });
    }
}
