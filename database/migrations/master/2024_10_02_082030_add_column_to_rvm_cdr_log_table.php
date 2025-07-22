<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddColumnToRvmCdrLogTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('rvm_cdr_log', function (Blueprint $table) {
            $table->string('user_id',255)->default(0);
            $table->string('voicemail_id',255)->default(0);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('rvm_cdr_log', function (Blueprint $table) {
            //
        });
    }
}
