<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAreacodeCityTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('areacode_city', function (Blueprint $table) {
            $table->id();
            $table->string('areacode',4);
            $table->string('country_code',4);
            $table->string('state_name',40);
            $table->string('state_code',4);
            $table->string('city_name',40);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('areacode_city');
    }
}
