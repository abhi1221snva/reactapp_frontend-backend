<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class ModifyEmailTypeColumnInCrmSmtpSetting extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('crm_smtp_setting', function (Blueprint $table) {
            DB::statement("ALTER TABLE crm_smtp_setting MODIFY COLUMN mail_type ENUM('online application', 'submission', 'notification', 'marketing_campaigns')");
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('crm_smtp_setting', function (Blueprint $table) {
            //
        });
    }
}
