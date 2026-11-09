<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The live-booking locks are gone: choosing Production on a courier account is the deliberate act. Drops the
 * account latch column and the safety.* settings, and the webhook public address setting (APP_URL is used instead).
 * Safe to run again.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->whereIn('key', ['safety.allow_live_lbc', 'safety.allow_live_spx', 'webhooks.public_base_url'])->delete();

        if (Schema::hasColumn('courier_accounts', 'allow_live_booking')) {
            Schema::table('courier_accounts', function (Blueprint $table) {
                $table->dropColumn('allow_live_booking');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('courier_accounts', 'allow_live_booking')) {
            Schema::table('courier_accounts', function (Blueprint $table) {
                $table->boolean('allow_live_booking')->default(false)->after('relay_secret');
            });
        }
    }
};
