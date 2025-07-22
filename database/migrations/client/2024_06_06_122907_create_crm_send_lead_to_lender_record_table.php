<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCrmSendLeadToLenderRecordTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('crm_send_lead_to_lender_record', function (Blueprint $table) {
            $table->id();
            $table->string('lender_id');
            $table->string('lead_id');
            $table->date('submitted_date');
            $table->string('lender_status_id')->nullable();
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
        Schema::dropIfExists('crm_send_lead_to_lender_record');
    }
}
