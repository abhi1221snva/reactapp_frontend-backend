<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('call_timers')) return;
         DB::statement("
            ALTER TABLE `call_timers`
            MODIFY `week_plan` LONGTEXT
            CHARACTER SET utf8mb4
            COLLATE utf8mb4_general_ci
            NULL
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
          DB::statement("
            ALTER TABLE `call_timers`
            MODIFY `week_plan` VARCHAR(255)
            CHARACTER SET utf8mb4
            COLLATE utf8mb4_general_ci
            NULL
        ");
    }
};
