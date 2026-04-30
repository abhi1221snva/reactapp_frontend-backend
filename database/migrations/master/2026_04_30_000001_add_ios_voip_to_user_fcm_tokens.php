<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class AddIosVoipToUserFcmTokens extends Migration
{
    public function up()
    {
        DB::connection('master')->statement(
            "ALTER TABLE `user_fcm_tokens` MODIFY `device_type` ENUM('web','android','ios','ios-voip') NOT NULL DEFAULT 'web'"
        );
    }

    public function down()
    {
        // Remove any ios-voip rows first to avoid data truncation
        DB::connection('master')->table('user_fcm_tokens')->where('device_type', 'ios-voip')->delete();

        DB::connection('master')->statement(
            "ALTER TABLE `user_fcm_tokens` MODIFY `device_type` ENUM('web','android','ios') NOT NULL DEFAULT 'web'"
        );
    }
}
