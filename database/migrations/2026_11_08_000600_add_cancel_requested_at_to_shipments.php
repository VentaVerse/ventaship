<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            // When cancel_state became `requested`: the sweep flags a cancel that never reached the courier.
            $table->timestamp('cancel_requested_at')->nullable()->after('cancelled_at');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropColumn('cancel_requested_at');
        });
    }
};
