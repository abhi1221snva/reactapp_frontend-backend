<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateVoicemailDropTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('voicemail_drop', function (Blueprint $table) {
            $table->integer('id', true);
            $table->string('ivr_id', 25);
            $table->string('ann_id', 25);
            $table->string('ivr_desc', 50);
            $table->string('speech_text', 1000)->nullable();
            $table->string('language')->nullable();
            $table->string('voice_name')->nullable();
            $table->enum('prompt_option', array('0', '1', '2'))->nullable()->comment('0-upload, 1-text to speech, 2-record');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('voicemail_drop');
    }
}
