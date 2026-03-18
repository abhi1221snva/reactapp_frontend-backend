<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auto-generated notes attached to a lead.
 * Each field update by a merchant creates one note row.
 */
class CreateCrmLeadNotesTable extends Migration
{
    public function up(): void
    {
        Schema::create('crm_lead_notes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('lead_id')->index();
            $table->text('note');
            $table->string('note_type', 50)->default('merchant_update')
                  ->comment('merchant_update | agent_note | system');
            $table->unsignedBigInteger('created_by')->nullable()->comment('Merchant ID or user ID');
            $table->string('user_type', 30)->default('merchant');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_lead_notes');
    }
}
