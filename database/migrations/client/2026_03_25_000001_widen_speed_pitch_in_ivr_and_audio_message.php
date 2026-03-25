<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Widen speed and pitch columns from varchar(5) to varchar(10) in both ivr
 * and audio_message tables. The old varchar(5) truncates/rejects 'medium'
 * (6 chars), causing save failures in strict MySQL mode.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['ivr', 'audio_message'] as $table) {
            if (Schema::hasColumn($table, 'speed')) {
                DB::statement("ALTER TABLE `{$table}` MODIFY `speed` VARCHAR(10) NULL DEFAULT NULL");
            }
            if (Schema::hasColumn($table, 'pitch')) {
                DB::statement("ALTER TABLE `{$table}` MODIFY `pitch` VARCHAR(10) NULL DEFAULT NULL");
            }
        }
    }

    public function down(): void
    {
        foreach (['ivr', 'audio_message'] as $table) {
            if (Schema::hasColumn($table, 'speed')) {
                DB::statement("ALTER TABLE `{$table}` MODIFY `speed` VARCHAR(5) NULL DEFAULT NULL");
            }
            if (Schema::hasColumn($table, 'pitch')) {
                DB::statement("ALTER TABLE `{$table}` MODIFY `pitch` VARCHAR(5) NULL DEFAULT NULL");
            }
        }
    }
};
