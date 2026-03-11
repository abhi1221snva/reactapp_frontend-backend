<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stores per-agent performance goals displayed on the CRM dashboard.
 * Progress is computed at query time by comparing actual metrics
 * against target_value for the given period.
 */
class CreateCrmAgentGoalsTable extends Migration
{
    public function up()
    {
        Schema::create('crm_agent_goals', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('agent_id')->index();
            $table->enum('metric', ['leads_closed', 'calls_made', 'revenue', 'deals_submitted']);
            $table->decimal('target_value', 12, 2)->default(0);
            $table->enum('period', ['daily', 'weekly', 'monthly', 'quarterly'])->default('monthly');
            $table->date('period_start');
            $table->date('period_end');
            $table->tinyInteger('status')->unsigned()->default(1);
            $table->unsignedInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['agent_id', 'period_start', 'period_end']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('crm_agent_goals');
    }
}
