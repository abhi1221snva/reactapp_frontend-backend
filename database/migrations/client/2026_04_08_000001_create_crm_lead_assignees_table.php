<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCrmLeadAssigneesTable extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('crm_lead_assignees')) {
            return;
        }

        Schema::create('crm_lead_assignees', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('lead_id');
            $table->unsignedBigInteger('user_id');
            $table->string('role', 30)->default('assignee');
            $table->timestamp('assigned_at')->useCurrent();
            $table->unsignedBigInteger('assigned_by')->nullable();
            $table->tinyInteger('is_active')->default(1);

            $table->unique(['lead_id', 'user_id', 'role'], 'uq_lead_user_role');
            $table->index(['user_id', 'is_active'], 'idx_user_active');
            $table->index(['lead_id', 'is_active'], 'idx_lead_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_lead_assignees');
    }
}
