<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSmsSettingTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('sms_setting', function (Blueprint $table) {
            $table->integer('id', true);
            $table->string('sms_url');
            $table->string('sender_name');
            $table->string('api_key');
            $table->unsignedTinyInteger("status")->default(1)->comment('1-active, 0-inactive');
            $table->integer('user_id')->default(0);
            $table->enum('sender_type', array(
                'system',
                'campaign',
                'user'
            ))->nullable();
            $table->unsignedInteger('campaign_id')->nullable();
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
        Schema::dropIfExists('sms_setting');
    }
}
