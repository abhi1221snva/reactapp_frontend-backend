<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddColumnsToLabelTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('label', function (Blueprint $table) {
            $table->enum('edit_mode', array('1','0'))->default(0)->nullable()->comment('0-no,1-yes');
            $table->enum('merchant_required', array('1','0'))->default(0)->nullable()->comment('0-no,1-yes');
            $table->enum('view_on_lead', array('1','0'))->default(0)->nullable()->comment('0-no,1-yes');
            $table->string('custom_values',50)->nullable();

        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('label', function (Blueprint $table) {
            //
        });
    }
}
