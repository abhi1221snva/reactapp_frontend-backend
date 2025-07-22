<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddDefaultValueToVoicemailDropLog extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('rvm_cdr_log', function (Blueprint $table) {
            $table->integer('voicemail_drop_log_id')->default(0)->change();
            $table->enum('timezone_status', array('0','1'))->default('1'); // 0-no,1-yes

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
            $table->integer('voicemail_drop_log_id')->default(null)->change();
        });
    }
}
