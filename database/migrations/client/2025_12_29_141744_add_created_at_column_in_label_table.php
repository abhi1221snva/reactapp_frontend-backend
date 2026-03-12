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
        if (!Schema::hasColumn('label', 'created_at')) {
            DB::statement("
            ALTER TABLE `label`
            ADD COLUMN `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
        ");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('label', function (Blueprint $table) {
            //
        });
    }
};
