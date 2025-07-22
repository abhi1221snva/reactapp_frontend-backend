<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddColumnsToOpenAiChatSetting extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('open_ai_chat_setting', function (Blueprint $table) {
            $table->string('webhook_url')->nullable();
            $table->string('sms_ai_api_url')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('open_ai_chat_setting', function (Blueprint $table) {
            //
        });
    }
}
