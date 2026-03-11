<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * crm_lead_values — EAV values table.
 * Stores one row per (lead, field_key) pair.
 * field_key references crm_labels.field_key.
 */
class CreateCrmLeadValuesTable extends Migration
{
    public function up(): void
    {
        Schema::create('crm_lead_values', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('lead_id');
            $table->string('field_key', 100);
            $table->text('field_value')->nullable();
            $table->timestamps();

            $table->unique(['lead_id', 'field_key']);
            $table->index('lead_id');
            $table->index('field_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_lead_values');
    }
}
