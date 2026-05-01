<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Use prefix length for TEXT column (first 191 chars)
        $conn = config('database.default');
        DB::connection($conn)->statement(
            'ALTER TABLE `crm_lead_values` ADD INDEX `idx_field_key_value` (`field_key`, `field_value`(191))'
        );
    }

    public function down(): void
    {
        Schema::table('crm_lead_values', function ($table) {
            $table->dropIndex('idx_field_key_value');
        });
    }
};
