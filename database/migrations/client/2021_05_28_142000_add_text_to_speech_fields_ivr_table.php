<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddTextToSpeechFieldsIvrTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('ivr', function (Blueprint $table) {

            if (Schema::hasColumn('ivr', 'language')) {
            Schema::table('ivr', function (Blueprint $table) {
                $table->dropColumn('language');
            });
        }

         if (Schema::hasColumn('ivr', 'voice_name')) {
            Schema::table('ivr', function (Blueprint $table) {
                $table->dropColumn('voice_name');
            });
        }



            $table->string('speech_text', 1000)->nullable();
            $table->string('language')->nullable();
            $table->string('voice_name')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasColumn('ivr', 'speech_text'))
        {
            Schema::table('ivr', function (Blueprint $table)
            {
                $table->dropColumn('speech_text');
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
    }
}
