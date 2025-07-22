<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateUserWiseVoiceAiTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('user_wise_voice_ai', function (Blueprint $table) {
            $table->id();
            $table->string('user_id');
            $table->string('extension');
            $table->text('speech_text');
            $table->string('language');
            $table->string('voice_name');
            $table->string('file_name');
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
        Schema::dropIfExists('user_wise_voice_ai');
    }
}
