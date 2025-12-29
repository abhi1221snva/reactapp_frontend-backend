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
            DB::statement("
        ALTER TABLE `custom_field_labels`
        ADD COLUMN `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
    ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('custom_field_labels', function (Blueprint $table) {
            //
        });
    }
};
