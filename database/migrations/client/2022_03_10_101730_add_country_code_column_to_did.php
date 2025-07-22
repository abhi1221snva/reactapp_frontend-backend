<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddCountryCodeColumnToDid extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('did', function (Blueprint $table) {
            $table->unsignedInteger('country_code')->after("forward_number")->nullable();
            $table->unsignedInteger('country_code_ooh')->after("forward_number_ooh")->nullable();

        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('did', function (Blueprint $table) {
            $table->dropColumn(["country_code"]);
            $table->dropColumn(["country_code_ooh"]);
            
            
        });
    }
}
