<?php

// database/migrations/xxxx_xx_xx_create_call_analysis_logs_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCallAnalysisLogsTable extends Migration
{
    public function up()
    {
        Schema::create('call_analysis_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('reference_id')->unique();
            $table->unsignedBigInteger('agent_id')->nullable();
            $table->unsignedBigInteger('campaign_id')->nullable();
            $table->json('response_data');  // Store full JSON here
            $table->integer('duration')->nullable(); // From your sample response
            $table->string('status')->nullable();    // success/failure
            $table->string('error_message')->nullable();
            $table->timestamps();

            // Optional: foreign keys if needed
            // $table->foreign('agent_id')->references('id')->on('agents')->onDelete('set null');
            // $table->foreign('campaign_id')->references('id')->on('campaigns')->onDelete('set null');
        });
    }

    public function down()
    {
        Schema::dropIfExists('call_analysis_logs');
    }
}

