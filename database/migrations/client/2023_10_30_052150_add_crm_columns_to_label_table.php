<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddCrmColumnsToLabelTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('label', function (Blueprint $table) {
            $table->string('label_title_url', 50)->nullable();
            $table->string('data_type',50)->nullable();
            $table->enum('required', array('1','0'))->default(1)->nullable()->comment('0-no,1-yes');
            $table->integer('display_order')->default(0);
            $table->string('column_name',50)->nullable();
            $table->enum('status', array('1','0'))->default(1)->nullable()->comment('0-no,1-yes');

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
