<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddWebhookColumnToCrmLeadStatus extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('crm_lead_status', function (Blueprint $table) {
            if (!Schema::hasColumn('crm_lead_status', 'webhook_status')) $table->enum('webhook_status', array('1','0'))->default(0)->nullable()->comment('0-inactive,1-active');
            if (!Schema::hasColumn('crm_lead_status', 'webhook_url')) $table->string('webhook_url')->nullable();

            
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('crm_lead_status', function (Blueprint $table) {
            //
        });
    }
}
