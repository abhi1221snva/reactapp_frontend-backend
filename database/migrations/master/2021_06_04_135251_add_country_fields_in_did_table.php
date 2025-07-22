<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddCountryFieldsInDidTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('did', function (Blueprint $table) {
            $table->string('area_code', 20)->nullable();
            $table->string('country_code', 20)->nullable();
            $table->string('provider', 10)->nullable();
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
            $table->dropColumn(["area_code"]);
            $table->dropColumn(["country_code"]);
            $table->dropColumn(["provider"]);
        });
    }
}
