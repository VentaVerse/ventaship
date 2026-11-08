<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outgoing_events', function (Blueprint $table) {
            // Set when ventaship:events-deliver queues the event, so the next runs don't queue it again meanwhile.
            $table->timestamp('dispatched_until')->nullable()->after('leased_until');
        });
    }

    public function down(): void
    {
        Schema::table('outgoing_events', function (Blueprint $table) {
            $table->dropColumn('dispatched_until');
        });
    }
};
