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
            $table->string('partner_api_key')->nullable();
            $table->string('client_id')->nullable();
            $table->string('auth_url')->nullable();
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
