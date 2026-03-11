<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * crm_leads — base lead record (system / administrative fields only).
 * All dynamic field values are stored in crm_lead_values.
 */
class CreateCrmLeadsTable extends Migration
{
    public function up(): void
    {
        Schema::create('crm_leads', function (Blueprint $table) {
            $table->id();
            $table->string('lead_status', 50)->default('new_lead');
            $table->string('lead_type', 20)->nullable();
            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedBigInteger('lead_source_id')->nullable();
            $table->unsignedBigInteger('lead_parent_id')->default(0);
            $table->string('unique_token', 255)->nullable()->unique();
            $table->string('unique_url', 255)->nullable()->unique();
            $table->tinyInteger('score')->unsigned()->default(0);
            $table->tinyInteger('is_deleted')->default(0);
            $table->string('group_id')->nullable();
            $table->string('opener_id')->nullable();
            $table->string('closer_id')->nullable();
            $table->enum('is_copied', ['1', '0'])->default('0');
            $table->string('copy_lead_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_deleted', 'lead_status']);
            $table->index('assigned_to');
            $table->index('created_at');
            $table->index('lead_source_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_leads');
    }
}
