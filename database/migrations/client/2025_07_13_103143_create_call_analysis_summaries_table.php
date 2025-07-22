<?php
// database/migrations/xxxx_xx_xx_create_call_analysis_summaries_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCallAnalysisSummariesTable extends Migration
{
    public function up()
    {
        Schema::create('call_analysis_summaries', function (Blueprint $table) {
            $table->id();
            $table->uuid('reference_id')->unique();
            $table->unsignedBigInteger('agent_id')->nullable();
            $table->unsignedBigInteger('campaign_id')->nullable();
            $table->integer('total_score')->nullable();
            $table->integer('max_score')->nullable();
            $table->integer('percentage')->nullable();
            $table->integer('agent_total_score')->nullable();
            $table->integer('agent_max_score')->nullable();
            $table->float('agent_average_score')->nullable();
            $table->string('lead_category_emoji')->nullable();
            $table->text('lead_category_desc')->nullable();
            $table->text('coaching_recommendation')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('call_analysis_summaries');
    }
}
