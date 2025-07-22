<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSmsAiLeadTempTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('sms_ai_lead_temp', function (Blueprint $table) {
$table->integer('campaign_id');
            $table->integer('list_id');
            $table->bigInteger('lead_id');
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
        Schema::dropIfExists('sms_ai_lead_temp');
    }
}
