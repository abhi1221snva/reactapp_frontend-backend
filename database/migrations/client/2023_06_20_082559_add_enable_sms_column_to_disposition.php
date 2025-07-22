<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddEnableSmsColumnToDisposition extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('disposition', function (Blueprint $table) {
            $table->unsignedTinyInteger("enable_sms")->default(0)->comment('1-active, 0-inactive');

        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('disposition', function (Blueprint $table) {
            $table->dropColumn('enable_sms');
        });
    }
}
