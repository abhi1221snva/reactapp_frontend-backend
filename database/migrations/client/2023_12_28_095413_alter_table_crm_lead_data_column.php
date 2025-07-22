<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AlterTableCrmLeadDataColumn extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('crm_lead_data', function (Blueprint $table) {
            \DB::statement("ALTER TABLE crm_lead_data CHANGE COLUMN phone phone_number varchar(50);");
        });

        Schema::table('crm_label', function (Blueprint $table) {
            \DB::statement("update crm_label set column_name='phone_number' where column_name='phone';");
            \DB::statement("update crm_label set column_name='company_name' where column_name='legal_company_name';");

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
