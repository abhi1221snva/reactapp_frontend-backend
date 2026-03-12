<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCrmLenderSubmissionsTable extends Migration
{
    public function up(): void
    {
        Schema::create('crm_lender_submissions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('lead_id');
            $table->unsignedBigInteger('lender_id');
            $table->string('lender_name')->nullable();
            $table->string('lender_email')->nullable();
            $table->string('application_pdf')->nullable();           // storage path of the PDF sent
            $table->enum('submission_status', [
                'pending', 'submitted', 'viewed', 'approved', 'declined', 'no_response',
            ])->default('pending');
            $table->enum('response_status', [
                'pending', 'approved', 'declined', 'needs_documents', 'no_response',
            ])->default('pending');
            $table->text('notes')->nullable();
            $table->text('response_note')->nullable();               // lender response note
            $table->unsignedBigInteger('submitted_by')->nullable();  // user who triggered
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('response_received_at')->nullable();
            $table->timestamps();

            $table->index('lead_id');
            $table->index('lender_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_lender_submissions');
    }
}
