<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AlterTableCrmLeadDataLegalCompanyName extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('crm_lead_data', function (Blueprint $table) {
            \DB::statement("ALTER TABLE crm_lead_data CHANGE COLUMN legal_company_name company_name varchar(100);");
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
