<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class AlterCrmSmsMessagesAddSystemDirection extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE `crm_sms_messages` MODIFY COLUMN `direction` ENUM('inbound','outbound','system') NOT NULL DEFAULT 'inbound'");
        DB::statement("ALTER TABLE `crm_sms_messages` MODIFY COLUMN `status` ENUM('pending','sent','delivered','failed','received','system') NOT NULL DEFAULT 'pending'");
    }

    public function down(): void
    {
        DB::statement("DELETE FROM `crm_sms_messages` WHERE `direction` = 'system'");
        DB::statement("ALTER TABLE `crm_sms_messages` MODIFY COLUMN `direction` ENUM('inbound','outbound') NOT NULL DEFAULT 'inbound'");
        DB::statement("ALTER TABLE `crm_sms_messages` MODIFY COLUMN `status` ENUM('pending','sent','delivered','failed','received') NOT NULL DEFAULT 'pending'");
    }
}
