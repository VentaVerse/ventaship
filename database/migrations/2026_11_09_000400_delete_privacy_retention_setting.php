<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** The personal-data retention policy is dropped: the stored setting is no longer read. */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->where('key', 'privacy.retention_days')->delete();
    }

    public function down(): void
    {
        // Nothing to restore: the setting no longer exists.
    }
};
