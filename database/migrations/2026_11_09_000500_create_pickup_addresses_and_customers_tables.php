<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Saved addresses. A pickup address holds the shipment `pickup` shape and a customer the `recipient` shape, both
 * vault-encrypted like the shipment columns; a shipment copies them, so editing or deleting one never changes it.
 * A customer is keyed by its phone in the local form ShipmentCreator::localPhone() stores ("09171234567").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pickup_addresses', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 30)->unique();
            $table->string('label');
            $table->boolean('is_default')->default(false)->index();
            $table->text('address'); // vault-encrypted json, shipment pickup shape
            $table->timestamps();
        });

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 30)->unique();
            $table->string('phone', 16)->unique();
            $table->string('name')->index();
            $table->text('address'); // vault-encrypted json, shipment recipient shape
            $table->unsignedInteger('shipments_count')->default(0);
            $table->timestamp('last_shipped_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
        Schema::dropIfExists('pickup_addresses');
    }
};
