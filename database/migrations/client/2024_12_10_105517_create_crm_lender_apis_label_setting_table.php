<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCrmLenderApisLabelSettingTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('crm_lender_apis_label_setting', function (Blueprint $table) {
            $table->id();
            $table->integer('crm_label_id');
            $table->string('ondeck_label')->nullable();
            $table->string('credibly_label')->nullable();
            $table->enum('status', array('1','0'))->default(1)->nullable()->comment('0-inactive,1-active');
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
        Schema::dropIfExists('crm_lender_apis_label_setting');
    }
}
