<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddMailTypeColumnToCrmSmtpSettingTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('crm_smtp_setting', function (Blueprint $table) {
            $table->enum('mail_type', array('online application','submission','notification'));

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
