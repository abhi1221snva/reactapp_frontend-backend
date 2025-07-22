<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateRinglessLeadReportTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('ringless_lead_report', function (Blueprint $table) {
            $table->integer('campaign_id');
            $table->integer('list_id');
            $table->bigInteger('lead_id');
            $table->bigInteger('merchant_number');
            $table->bigInteger('cli');
            $table->enum('delivery_status', array('1','0'))->default('0')->comment('1-yes,0-no');
            $table->primary(['campaign_id','lead_id']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('ringless_lead_report');
    }
}
