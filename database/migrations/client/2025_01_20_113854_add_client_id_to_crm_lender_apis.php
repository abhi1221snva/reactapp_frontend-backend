<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddClientIdToCrmLenderApis extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('crm_lender_apis', function (Blueprint $table) {
            if (!Schema::hasColumn('crm_lender_apis', 'partner_api_key')) $table->string('partner_api_key')->nullable();
            if (!Schema::hasColumn('crm_lender_apis', 'client_id')) $table->string('client_id')->nullable();
            if (!Schema::hasColumn('crm_lender_apis', 'auth_url')) $table->string('auth_url')->nullable();
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
