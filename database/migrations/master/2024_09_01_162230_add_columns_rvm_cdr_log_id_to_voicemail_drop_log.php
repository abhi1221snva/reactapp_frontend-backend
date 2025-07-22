<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddColumnsRvmCdrLogIdToVoicemailDropLog extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('voicemail_drop_log', function (Blueprint $table) {
            $table->string('rvm_cdr_log_id', 15)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('voicemail_drop_log', function (Blueprint $table) {
            //
        });
    }
}
