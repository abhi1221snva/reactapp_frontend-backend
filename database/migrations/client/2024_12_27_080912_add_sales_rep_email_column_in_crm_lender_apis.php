<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddSalesRepEmailColumnInCrmLenderApis extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('crm_lender_apis', function (Blueprint $table) {
            $table->string('sales_rep_email')->nullable()->after('crm_lender_id');

        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('crm_lender_apis', function (Blueprint $table) {
            //
        });
    }
}
