<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddPromptOptionInUserWiseVoiceAiTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('user_wise_voice_ai', function (Blueprint $table) {
            $table->enum('prompt_option', array('0', '1', '2'))->nullable()->comment('0-upload, 1-text to speech, 2-record');

        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('user_wise_voice_ai', function (Blueprint $table) {
            //
        });
    }
}
