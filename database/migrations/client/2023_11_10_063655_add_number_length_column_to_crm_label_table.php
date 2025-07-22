<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddNumberLengthColumnToCrmLabelTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('crm_label', function (Blueprint $table) {
            $table->integer('number_length')->nullable();
            $table->enum('label_type', array('open','system'))->default('open');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('crm_label', function (Blueprint $table) {
            //
        });
    }
}
