<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateDripV2EnrollmentsTable extends Migration
{
    public function up(): void
    {
        Schema::create('drip_v2_enrollments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('campaign_id');
            $table->unsignedBigInteger('lead_id');
            $table->unsignedBigInteger('current_step_id')->nullable();
            $table->enum('status', ['active', 'completed', 'stopped', 'failed'])->default('active');
            $table->unsignedBigInteger('enrolled_by')->nullable();
            $table->enum('enrolled_via', ['manual', 'trigger', 'api'])->default('manual');
            $table->string('trigger_rule', 100)->nullable();
            $table->timestamp('next_send_at')->nullable();
            $table->string('stopped_reason', 255)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('stopped_at')->nullable();
            $table->timestamps();

            $table->unique(['campaign_id', 'lead_id']);
            $table->index('lead_id');
            $table->index('status');
            $table->index('next_send_at');
            $table->foreign('campaign_id')->references('id')->on('drip_v2_campaigns')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drip_v2_enrollments');
    }
}
