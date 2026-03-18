<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit log: every field-level change made to a lead.
 * Stored in the per-tenant (client) database.
 */
class CreateCrmLeadLogsTable extends Migration
{
    public function up(): void
    {
        Schema::create('crm_lead_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('lead_id')->index();
            $table->string('field_name', 100);
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable()->comment('Merchant ID or user ID');
            $table->string('user_type', 30)->default('merchant')->comment('merchant | agent | admin');
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['lead_id', 'field_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_lead_logs');
    }
}
