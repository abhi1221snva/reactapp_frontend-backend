<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCrmPdfTemplatesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('crm_custom_templates', function (Blueprint $table) {
            $table->increments('id');
            $table->string('template_name', 200);
            $table->longText('template_html');
            $table->string('custom_type', 255);
            //$table->enum('send_bcc', array('1','0'))->default(1)->nullable()->comment('0-no,1-yes');
            $table->enum('status', array('1','0'))->default(1)->nullable()->comment('0-inactive,1-active');
            $table->softDeletes();
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
        Schema::dropIfExists('crm_custom_templates');
    }
};
