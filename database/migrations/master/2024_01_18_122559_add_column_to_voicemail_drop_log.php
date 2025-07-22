<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddColumnToVoicemailDropLog extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('voicemail_drop_log', function (Blueprint $table) {
            $table->integer('user_id')->default(0);
            $table->integer('voicemail_id')->default(0);
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
