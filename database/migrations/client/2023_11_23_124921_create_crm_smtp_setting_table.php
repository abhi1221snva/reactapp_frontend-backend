<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCrmSmtpSettingTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('crm_smtp_setting', function (Blueprint $table) {
            $table->id();
            $table->string('mail_driver'); //sengrid
            $table->string('mail_host'); //smtp.sendgrid.net
            $table->string('mail_username'); //apikey
            $table->string('mail_password'); //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
            $table->string('mail_encryption'); //// TLS or SSL, depending on your server configuration
            $table->string('mail_port'); // 587
            $table->string('sender_email');//noreply@visioncap.net
            $table->string('sender_name');//Online Application
            $table->enum('send_email_via', array('user_email','custom'))->default('user_email');
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
        Schema::dropIfExists('crm_smtp_setting');
    }
}
