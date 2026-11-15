<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ventaship's own shipment statuses, the words every courier status maps to. Seeded with the phases the couriers
 * already map onto, so nothing changes until the owner edits them.
 */
return new class extends Migration
{
    private const SEED = [
        ['to_handover', 'To hand over'],
        ['in_transit', 'In transit'],
        ['attention', 'Needs attention'],
        ['delivered', 'Delivered'],
        ['failed', 'Failed'],
        ['lost_damaged', 'Lost or damaged'],
        ['returning', 'Returning'],
        ['cancelled', 'Cancelled'],
    ];

    public function up(): void
    {
        Schema::create('shipment_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('key', 32)->unique();
            $table->string('name', 40);
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        $now = now();
        DB::table('shipment_statuses')->insert(array_map(fn (array $s, int $i) => [
            'key' => $s[0], 'name' => $s[1], 'enabled' => true, 'sort' => ($i + 1) * 10, 'created_at' => $now, 'updated_at' => $now,
        ], self::SEED, array_keys(self::SEED)));
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_statuses');
    }
};
