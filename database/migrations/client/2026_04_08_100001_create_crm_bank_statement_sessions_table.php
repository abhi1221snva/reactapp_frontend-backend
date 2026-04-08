<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCrmBankStatementSessionsTable extends Migration
{
    public function up(): void
    {
        Schema::create('crm_bank_statement_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('lead_id')->nullable();
            $table->string('batch_id', 100)->nullable();
            $table->string('session_id', 100)->unique();
            $table->string('file_name', 500)->nullable();
            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])->default('pending');
            $table->enum('model_tier', ['lsc_basic', 'lsc_pro', 'lsc_max'])->default('lsc_basic');
            $table->json('summary_data')->nullable();
            $table->json('mca_analysis')->nullable();
            $table->json('monthly_data')->nullable();
            $table->decimal('fraud_score', 5, 2)->nullable();
            $table->decimal('total_revenue', 14, 2)->nullable();
            $table->decimal('total_deposits', 14, 2)->nullable();
            $table->integer('nsf_count')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->timestamp('analyzed_at')->nullable();
            $table->timestamps();

            $table->index('lead_id');
            $table->index('batch_id');
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_bank_statement_sessions');
    }
}
