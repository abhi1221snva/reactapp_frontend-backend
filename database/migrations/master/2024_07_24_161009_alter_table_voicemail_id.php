<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AlterTableVoicemailId extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('voicemail_drop_log', function (Blueprint $table) {
            DB::statement("ALTER TABLE `voicemail_drop_log` CHANGE `user_id` `user_id` VARCHAR(100) COLLATE latin1_swedish_ci NOT NULL");
            DB::statement("ALTER TABLE `voicemail_drop_log` CHANGE `voicemail_id` `voicemail_id` VARCHAR(100) COLLATE latin1_swedish_ci NOT NULL");
            
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
