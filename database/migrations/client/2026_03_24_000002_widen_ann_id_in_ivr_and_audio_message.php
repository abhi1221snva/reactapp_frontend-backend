<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Widen ann_id from varchar(25) to varchar(255) in both ivr and audio_message tables.
 *
 * The old varchar(25) limit silently truncates uploaded file paths such as
 * "uploads/1711234567_5f9a3b1c2d4e5.mp3" (~36 chars), producing a broken path
 * that causes 404 errors when the frontend attempts to stream the audio file.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['ivr', 'audio_message'] as $table) {
            if (Schema::hasColumn($table, 'ann_id')) {
                DB::statement("ALTER TABLE `{$table}` MODIFY `ann_id` VARCHAR(255) NULL");
            }
        }
    }

    public function down(): void
    {
        foreach (['ivr', 'audio_message'] as $table) {
            if (Schema::hasColumn($table, 'ann_id')) {
                DB::statement("ALTER TABLE `{$table}` MODIFY `ann_id` VARCHAR(25) NULL");
            }
        }
    }
};
