<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddColumnsToSmsAi extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('sms_ai', function (Blueprint $table) {
            $table->enum('view_type', array('1','0'))->default(1)->nullable()->comment('0-inactive,1-active');
            $table->string('last_balance', 255)->comment('sms_ai_wallet balance');
            $table->string('current_balance', 255)->comment('sms_ai_wallet balance');
            $table->string('per_sms_charge_sms_ai', 255);
            
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('sms_ai', function (Blueprint $table) {
            //
        });
    }
}
