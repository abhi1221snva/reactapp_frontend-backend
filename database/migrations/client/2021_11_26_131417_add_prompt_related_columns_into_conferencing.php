<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddPromptRelatedColumnsIntoConferencing extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
         Schema::table('conferencing', function (Blueprint $table) {
             if(!Schema::hasColumn('conferencing', 'speech_text')) {
                 $table->string('speech_text', 1000)->nullable();
             }
             if(!Schema::hasColumn('conferencing', 'prompt_option')) {
                 $table->enum('prompt_option', array('0', '1', '2'))->nullable()->comment('0-upload, 1-text to speech, 2-record');
             }
             if(!Schema::hasColumn('conferencing', 'language')) {
                 $table->string('language')->nullable();
             }
             if(!Schema::hasColumn('conferencing', 'voice_name')) {
                 $table->string('voice_name')->nullable();
             }
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasColumn('conferencing', 'speech_text')) {
            Schema::table('conferencing', function (Blueprint $table) {
                $table->dropColumn('speech_text');
            });
        }
        if (Schema::hasColumn('conferencing', 'prompt_option')) {
            Schema::table('conferencing', function (Blueprint $table) {
                $table->dropColumn('prompt_option');
            });
        }
        if (Schema::hasColumn('conferencing', 'language')) {
            Schema::table('conferencing', function (Blueprint $table) {
                $table->dropColumn('language');
            });
        }
        if (Schema::hasColumn('conferencing', 'voice_name')) {
            Schema::table('conferencing', function (Blueprint $table) {
                $table->dropColumn('voice_name');
            });
        }
    }
}
