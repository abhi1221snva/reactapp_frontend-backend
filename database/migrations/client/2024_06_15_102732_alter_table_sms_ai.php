<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AlterTableSmsAi extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('sms_ai', function (Blueprint $table) {
             \DB::statement("ALTER TABLE `sms_ai` CHANGE `operator` `operator` ENUM('nexmo','didforsale','telnyx','plivo','voxox','twilio') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'telnyx';");
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
