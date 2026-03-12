<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddWebhookMethodColumnToCrmLeadStatus extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('crm_lead_status', function (Blueprint $table) {
            if (!Schema::hasColumn('crm_lead_status', 'webhook_method')) $table->enum('webhook_method', array('get','post'))->default('post');
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
