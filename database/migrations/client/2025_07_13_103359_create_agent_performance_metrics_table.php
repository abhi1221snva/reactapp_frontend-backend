<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAgentPerformanceMetricsTable extends Migration
{
    public function up()
    {
        Schema::create('agent_performance_metrics', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('analysis_id');
            $table->string('category');
            $table->string('score')->nullable();
            $table->string('score_display')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('analysis_id')->references('id')->on('call_analysis_summaries')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('agent_performance_metrics');
    }
}

