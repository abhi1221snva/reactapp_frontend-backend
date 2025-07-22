<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AlterTableCrmLeadDataValues extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('crm_lead_data', function (Blueprint $table) {
            \DB::statement("ALTER TABLE `crm_lead_data` CHANGE `first_name` `first_name` VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL;");
            \DB::statement("ALTER TABLE `crm_lead_data` CHANGE `last_name` `last_name` VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL;");
            \DB::statement("ALTER TABLE `crm_lead_data` CHANGE `email` `email` VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL;");
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('crm_lead_data', function (Blueprint $table) {
            //
        });
    }
}
