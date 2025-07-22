<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateRinglessVoiceTemplatesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('ringless_voice_templates', function (Blueprint $table) {
            $table->id();
            $table->string('ivr_id', 25);
            $table->string('ann_id', 25);
            $table->string('ivr_desc', 50);
            $table->string('language', 255);
            $table->string('voice_name', 255);
            $table->string('speed',5)->default(1);
            $table->string('pitch',5)->default(0);
            $table->text('speech_text')->nullable();
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
        Schema::dropIfExists('ringless_voice_templates');
    }
}
