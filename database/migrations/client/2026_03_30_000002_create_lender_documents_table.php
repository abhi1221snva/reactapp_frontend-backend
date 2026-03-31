<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks documents uploaded to an external lender for a specific application.
 */
class CreateLenderDocumentsTable extends Migration
{
    public function up(): void
    {
        Schema::create('lender_documents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('lead_id')->index();
            $table->string('business_id', 100)->index();
            $table->string('lender_name', 50)->default('ondeck');
            $table->string('document_type', 100)->nullable();
            $table->string('document_need', 100)->nullable();   // OnDeck's documentNeed field
            $table->string('file_path')->nullable();
            $table->string('original_name')->nullable();
            $table->enum('upload_status', ['pending', 'uploaded', 'failed'])->default('pending')->index();
            $table->json('lender_response')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamps();

            $table->index(['lead_id', 'business_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lender_documents');
    }
}
