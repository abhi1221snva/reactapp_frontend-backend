<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_lead_values', function (Blueprint $table) {
            $table->index(['field_key', 'field_value'], 'idx_field_key_value');
        });
    }

    public function down(): void
    {
        Schema::table('crm_lead_values', function (Blueprint $table) {
            $table->dropIndex('idx_field_key_value');
        });
    }
};
