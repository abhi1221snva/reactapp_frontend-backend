<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddPromptOptionInIvr extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('ivr', function (Blueprint $table) {
            Schema::table('ivr', function (Blueprint $table) {
                if(!Schema::hasColumn('ivr', 'prompt_option')) {
                    $table->enum('prompt_option', array('0', '1', '2'))->nullable()->comment('0-upload, 1-text to speech, 2-record');
                }
            });
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('ivr', function (Blueprint $table) {
            if (Schema::hasColumn('ivr', 'prompt_option')) {
                Schema::table('ivr', function (Blueprint $table) {
                    $table->dropColumn('prompt_option');
                });
            }
        });
    }
}
