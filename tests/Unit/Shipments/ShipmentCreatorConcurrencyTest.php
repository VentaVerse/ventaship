<?php

namespace Tests\Unit\Shipments;

use App\Api\ApiError;
use App\Models\ClientApp;
use App\Models\Shipment;
use App\Shipments\ShipmentCreator;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PDOException;
use Tests\Concerns\BuildsShipments;
use Tests\TestCase;

/**
 * MySQL can answer two concurrent creates of the same client_reference with a deadlock (1213, on the gap lock of
 * the holder lookup) instead of a unique violation. SQLite cannot deadlock, so the deadlock is injected on the
 * holder lookup. DatabaseMigrations, not RefreshDatabase: the creator must run at transaction level 0, as in
 * production, where MySQL has already rolled the whole transaction back.
 */
class ShipmentCreatorConcurrencyTest extends TestCase
{
    use BuildsShipments, DatabaseMigrations;

    private int $deadlocks = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_deadlock_against_a_live_holder_answers_409_with_that_shipment(): void
    {
        $account = $this->fakeDefaultAccount();
        $app = ClientApp::factory()->create();
        $holder = Shipment::factory()->create([
            'client_app_id' => $app->id, 'courier_account_id' => $account->id,
            'client_reference' => '4211', 'active_reference' => '4211', 'state' => 'booking',
        ]);
        $this->deadlockHolderLookup(times: 5);

        try {
            app(ShipmentCreator::class)->create($app, $this->shipmentBody());
            $this->fail('expected a 409');
        } catch (ApiError $e) {
            $this->assertSame(409, $e->status);
            $this->assertSame('active_shipment_exists', $e->errorCode);
            $this->assertSame($holder->public_id, $e->errors['shipment']);
        }

        $this->assertSame(1, $this->deadlocks, 'a live holder answers at once, without retrying');
        $this->assertSame(1, Shipment::query()->count());
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_deadlock_without_a_live_holder_is_retried(): void
    {
        $this->fakeDefaultAccount();
        $app = ClientApp::factory()->create();
        $this->deadlockHolderLookup(times: 1);

        $shipment = app(ShipmentCreator::class)->create($app, $this->shipmentBody());

        $this->assertSame(1, $this->deadlocks);
        $this->assertSame('queued', $shipment->state);
        $this->assertSame('4211', $shipment->refresh()->active_reference);
    }

    public function test_repeated_deadlocks_give_up_with_the_database_error(): void
    {
        $this->fakeDefaultAccount();
        $app = ClientApp::factory()->create();
        $this->deadlockHolderLookup(times: 10);

        $this->expectException(QueryException::class);

        try {
            app(ShipmentCreator::class)->create($app, $this->shipmentBody());
        } finally {
            $this->assertSame(3, $this->deadlocks);
            $this->assertSame(0, Shipment::query()->count());
        }
    }

    /** The locking holder lookup fails with MySQL's deadlock error the next $times times it runs. */
    private function deadlockHolderLookup(int $times): void
    {
        DB::listen(function (QueryExecuted $query) use ($times) {
            if ($this->deadlocks >= $times || DB::transactionLevel() === 0 || ! str_contains($query->sql, '"active_reference" = ?') || ! str_starts_with($query->sql, 'select')) {
                return;
            }
            $this->deadlocks++;

            throw new QueryException(
                $query->connectionName,
                $query->sql,
                $query->bindings,
                new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction'),
            );
        });
    }
}
