<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {

        Schema::create('csv_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('filename');
            $table->unsignedInteger('rows')->default(0);
            $table->unsignedInteger('created')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->json('errors')->nullable();
            $table->string('status', 16)->default('pending');
            $table->timestamps();
        });

        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 30)->unique();
            $table->foreignId('client_app_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('courier_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('courier', 16)->nullable();
            $table->enum('source', ['api', 'manual', 'csv'])->default('api');

            $table->string('client_reference', 64);
            $table->string('active_reference', 64)->nullable();
            $table->string('order_label', 64)->nullable();
            $table->string('courier_ref', 64)->nullable()->index();

            $table->string('state', 16)->default('queued'); // draft, queued, booking, booked, unknown, failed, cancelled
            $table->string('cancel_state', 16)->nullable();
            $table->string('phase', 32)->nullable();
            $table->string('courier_status', 100)->nullable();
            $table->string('tracking_number', 64)->nullable();

            $table->string('method', 16)->nullable(); // pickup, dropoff
            $table->string('service', 64)->nullable();
            $table->json('options')->nullable();

            $table->longText('pickup')->nullable();    // vault-encrypted json
            $table->longText('recipient')->nullable(); // vault-encrypted json
            $table->text('courier_note')->nullable();  // vault-encrypted
            $table->json('items')->nullable();
            $table->unsignedInteger('items_count')->default(0);
            $table->string('items_summary', 255)->nullable();

            $table->enum('payment_method', ['cod', 'prepaid'])->default('prepaid');
            $table->string('payment_state', 16)->nullable();
            $table->timestamp('remitted_at')->nullable();
            $table->decimal('cod_amount', 12, 2)->nullable();
            $table->decimal('declared_value', 12, 2)->nullable();
            $table->decimal('subtotal', 12, 2)->nullable();
            $table->decimal('total', 12, 2)->nullable();

            $table->decimal('fee_amount', 12, 2)->nullable();
            $table->string('fee_currency', 3)->nullable();
            $table->decimal('actual_fee', 12, 2)->nullable();
            $table->json('meta')->nullable();
            $table->json('metadata')->nullable();

            $table->text('notice')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->boolean('needs_attention')->default(false);
            $table->string('attention_reason')->nullable();

            $table->unsignedInteger('event_sequence')->default(0);
            $table->timestamp('booking_started_at')->nullable();
            $table->timestamp('booked_at')->nullable();
            $table->timestamp('terminal_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('last_polled_at')->nullable();
            $table->timestamp('next_poll_at')->nullable();
            $table->timestamp('last_event_at')->nullable();
            $table->timestamp('pii_purged_at')->nullable();
            $table->timestamps();

            $table->unique(['client_app_id', 'active_reference'], 'shipments_app_active_ref_unique');
            $table->index(['courier_account_id', 'tracking_number'], 'shipments_account_tracking_idx');
            $table->index(['state', 'next_poll_at'], 'shipments_poll_idx');
            $table->index('phase', 'shipments_phase_idx');
            $table->index('created_at', 'shipments_created_idx');
        });

        Schema::create('tracking_numbers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('series_id')->constrained('tracking_series')->cascadeOnDelete();
            $table->unsignedBigInteger('number');
            $table->string('status', 16)->default('reserved'); // reserved, used, void
            $table->foreignId('shipment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('outcome')->nullable();
            $table->timestamps();
            $table->unique(['series_id', 'number'], 'tracking_numbers_unique');
        });

        Schema::create('webhook_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('courier', 16);
            $table->foreignId('courier_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('routed_by', 16)->default('none'); // token, app_id, tracking, none
            $table->string('status', 16)->default('received');
            $table->string('external_id', 100)->nullable();
            $table->json('headers')->nullable();
            $table->mediumText('body')->nullable();
            $table->timestamp('received_at')->useCurrent();
            $table->timestamp('processed_at')->nullable();
            $table->text('error')->nullable();
            $table->unsignedSmallInteger('relay_attempts')->default(0);
            $table->unique(['courier_account_id', 'external_id'], 'receipts_account_external_unique');
            $table->index(['status', 'received_at'], 'receipts_status_idx');
        });

        Schema::create('shipment_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->string('source', 16); // webhook, poll, refresh, system, admin
            $table->string('courier_status', 100);
            $table->string('label')->nullable();
            $table->string('phase', 32)->nullable();
            $table->timestamp('occurred_at');
            $table->text('remarks')->nullable();
            $table->json('raw')->nullable();
            $table->foreignId('receipt_id')->nullable()->constrained('webhook_receipts')->nullOnDelete();
            $table->timestamps();
            $table->unique(['shipment_id', 'courier_status', 'occurred_at'], 'shipment_events_unique');
        });

        Schema::create('shipment_labels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->string('format', 16);
            $table->string('path');
            $table->unsignedInteger('bytes');
            $table->char('sha256', 64);
            $table->timestamp('fetched_at');
            $table->timestamps();
        });

        Schema::create('outgoing_events', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 30)->unique();
            $table->foreignId('client_app_id')->constrained()->cascadeOnDelete();
            $table->string('type', 64);
            $table->foreignId('shipment_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('sequence')->default(0);
            $table->json('payload');
            $table->string('status', 16)->default('pending'); // pending, delivered, failed
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('window_started_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->index(['status', 'next_attempt_at'], 'outgoing_due_idx');
        });

        Schema::create('outgoing_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outgoing_event_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_app_id')->constrained()->cascadeOnDelete();
            $table->string('key', 128);
            $table->char('request_hash', 64);
            $table->string('status', 16)->default('processing');
            $table->mediumText('response')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['client_app_id', 'key'], 'idempotency_app_key_unique');
        });

        Schema::create('request_signatures', function (Blueprint $table) {
            $table->id();
            $table->char('signature_hash', 64)->unique();
            $table->timestamp('created_at')->useCurrent();
            $table->index('created_at', 'request_signatures_created_idx');
        });

        Schema::create('api_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_app_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('api_key_id')->nullable()->constrained()->nullOnDelete();
            $table->string('method', 8);
            $table->string('path', 255);
            $table->unsignedSmallInteger('status');
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('idempotency_key', 128)->nullable();
            $table->string('error')->nullable();
            $table->string('request_id', 64)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });

        Schema::create('courier_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('courier_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('shipment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('courier', 16);
            $table->string('operation', 64);
            $table->string('method', 8);
            $table->string('host_path', 255);
            $table->unsignedSmallInteger('status')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('error')->nullable();
            $table->text('excerpt')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        foreach (['courier_calls', 'api_requests', 'request_signatures', 'idempotency_keys', 'outgoing_attempts', 'outgoing_events', 'shipment_labels', 'shipment_events', 'webhook_receipts', 'tracking_numbers', 'shipments', 'csv_imports'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
