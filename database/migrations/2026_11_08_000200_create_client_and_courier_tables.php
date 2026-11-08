<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_apps', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 30)->unique();
            $table->string('name');
            $table->string('status', 16)->default('active');
            $table->string('webhook_url', 2048)->nullable();
            $table->text('webhook_secret')->nullable(); // vault-encrypted
            $table->json('webhook_events')->nullable(); // null = all
            $table->timestamps();
        });

        Schema::create('api_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_app_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('key_prefix', 8);
            $table->char('key_hash', 64)->unique();
            $table->text('signing_secret'); // vault-encrypted
            $table->json('scopes')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('courier_accounts', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 30)->unique();
            $table->string('courier', 16);
            $table->string('label');
            $table->string('mode', 16)->default('staging');
            $table->string('status', 16)->default('active');
            $table->boolean('is_default')->default(false);
            $table->string('default_slot', 16)->nullable()->unique();
            $table->char('webhook_token_hash', 64)->nullable()->unique();
            $table->text('webhook_token')->nullable(); // vault-encrypted
            $table->string('external_ref')->nullable()->index();
            $table->string('relay_url', 2048)->nullable();
            $table->text('relay_secret')->nullable(); // vault-encrypted
            $table->boolean('allow_live_booking')->default(false);
            $table->timestamp('last_check_at')->nullable();
            $table->boolean('last_check_ok')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index('courier', 'courier_accounts_courier_idx');
        });

        Schema::create('courier_account_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('courier_account_id')->constrained()->cascadeOnDelete();
            $table->string('key', 100);
            $table->text('value')->nullable();
            $table->timestamps();
            $table->unique(['courier_account_id', 'key'], 'cas_account_key_unique');
        });

        Schema::create('courier_status_labels', function (Blueprint $table) {
            $table->id();
            $table->string('courier', 16)->unique();
            $table->json('labels');
            $table->timestamp('refreshed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('status_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('courier', 16);
            $table->string('courier_status', 100);
            $table->string('phase', 32)->nullable();
            $table->string('label')->nullable();
            $table->timestamps();
            $table->unique(['courier', 'courier_status'], 'status_mappings_unique');
        });

        Schema::create('tracking_series', function (Blueprint $table) {
            $table->id();
            $table->foreignId('courier_account_id')->constrained()->cascadeOnDelete();
            $table->string('courier', 16);
            $table->unsignedBigInteger('first_number');
            $table->unsignedBigInteger('last_number');
            $table->unsignedBigInteger('last_issued_number')->nullable();
            $table->unsignedInteger('low_water_mark')->default(200);
            $table->timestamps();
            $table->unique(['courier', 'first_number'], 'tracking_series_unique');
        });
    }

    public function down(): void
    {
        foreach (['tracking_series', 'status_mappings', 'courier_status_labels', 'courier_account_settings', 'courier_accounts', 'api_keys', 'client_apps'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
