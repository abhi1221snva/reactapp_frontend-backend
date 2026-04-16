<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddMappedFieldKeyToCrmLeadSourceFields extends Migration
{
    public function up()
    {
        if (!Schema::hasColumn('crm_lead_source_fields', 'mapped_field_key')) {
            Schema::table('crm_lead_source_fields', function (Blueprint $table) {
                $table->string('mapped_field_key', 100)->nullable()->after('field_name');
            });
        }
    }

    public function down()
    {
        Schema::table('crm_lead_source_fields', function (Blueprint $table) {
            $table->dropColumn('mapped_field_key');
        });
    }
}
