<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCrmLeadActivityTable extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('crm_lead_activity')) return;

        Schema::create('crm_lead_activity', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('lead_id')->index();
            $table->unsignedInteger('user_id')->nullable()->index();
            $table->string('activity_type', 50)->index();   // note_added, status_change, field_update, lender_submitted, lender_response, email_sent, system …
            $table->string('subject', 500);
            $table->text('body')->nullable();
            $table->json('meta')->nullable();
            $table->string('source_type', 30)->default('manual'); // manual | crm_log | crm_notifications | api
            $table->unsignedBigInteger('source_id')->nullable();  // FK to originating row (optional)
            $table->tinyInteger('is_pinned')->default(0)->index();
            $table->timestamps();

            $table->index(['lead_id', 'activity_type']);
            $table->index(['lead_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_lead_activity');
    }
}
