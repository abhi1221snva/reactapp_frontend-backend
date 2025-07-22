<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCrmSmsTemplatesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('crm_sms_templates', function (Blueprint $table) {
            $table->increments('id');
            $table->string('template_name',50);
            $table->longText('template_html');
            $table->string('lead_status',50);
            $table->enum('status', array('1','0'))->default(1)->nullable()->comment('0-no,1-yes');
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
        Schema::dropIfExists('crm_sms_templates');
    }
}
