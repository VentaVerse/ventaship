<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outgoing_events', function (Blueprint $table) {
            // status: pending, delivered, failed, skipped. A worker delivering the event holds it until this time; a crashed worker's lease simply runs out.
            $table->timestamp('leased_until')->nullable()->after('next_attempt_at');
            $table->index(['shipment_id', 'sequence'], 'outgoing_shipment_seq_idx');
        });
    }

    public function down(): void
    {
        Schema::table('outgoing_events', function (Blueprint $table) {
            $table->dropIndex('outgoing_shipment_seq_idx');
            $table->dropColumn('leased_until');
        });
    }
};
