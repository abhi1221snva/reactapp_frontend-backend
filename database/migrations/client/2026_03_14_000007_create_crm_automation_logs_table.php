<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCrmAutomationLogsTable extends Migration
{
    public function up()
    {
        Schema::create('crm_automation_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('automation_id');
            $table->unsignedBigInteger('lead_id');
            $table->enum('status', ['success','failed','skipped'])->default('success');
            $table->json('result')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
            $table->index(['automation_id']);
            $table->index(['lead_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('crm_automation_logs');
    }
}
