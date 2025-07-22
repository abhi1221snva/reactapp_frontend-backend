<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddCallScreeningToDid extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('did', function (Blueprint $table) {
            $table->enum('call_screening_status', array('1','0'))->nullable()->comment('0-inactive,1-active');
            $table->string('call_screening_ivr_id', 50)->nullable();
            $table->string('ann_id', 50)->nullable();
            $table->string('language')->nullable();
            $table->string('voice_name')->nullable();
            $table->string('speech_text', 1000)->nullable();
            $table->string('ivr_audio_option', 100)->nullable();
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
        Schema::table('did', function (Blueprint $table) {
        if (Schema::hasColumn('ivr', 'call_screening_status'))
        {
            Schema::table('ivr', function (Blueprint $table)
            {
                $table->dropColumn('call_screening_status');
            });
        }
        if (Schema::hasColumn('ivr', 'ann_id'))
        {
            Schema::table('ivr', function (Blueprint $table)
            {
                $table->dropColumn('ann_id');
            });
        }
        if (Schema::hasColumn('ivr', 'language'))
        {
            Schema::table('ivr', function (Blueprint $table)
            {
                $table->dropColumn('language');
            });
        }

        if (Schema::hasColumn('ivr', 'voice_name'))
        {
            Schema::table('ivr', function (Blueprint $table)
            {
                $table->dropColumn('voice_name');
            });
        }

        if (Schema::hasColumn('ivr', 'speech_text'))
        {
            Schema::table('ivr', function (Blueprint $table)
            {
                $table->dropColumn('speech_text');
            });
        }

        if (Schema::hasColumn('ivr', 'ivr_audio_option'))
        {
            Schema::table('ivr', function (Blueprint $table)
            {
                $table->dropColumn('ivr_audio_option');
            });
        }

        if (Schema::hasColumn('ivr', 'prompt_option'))
        {
            Schema::table('ivr', function (Blueprint $table)
            {
                $table->dropColumn('prompt_option');
            });
        }
        });
    }
}
