<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks the lifecycle of a lead's application with an external lender (OnDeck, etc.).
 * Stores the lender-assigned businessID which is required for all subsequent API calls.
 */
class CreateLenderApplicationsTable extends Migration
{
    public function up(): void
    {
        Schema::create('lender_applications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('lead_id')->index();
            $table->string('lender_name', 50)->default('ondeck')->index();
            $table->string('business_id', 100)->nullable()->index();
            $table->string('application_number', 100)->nullable();
            $table->string('external_customer_id', 100)->nullable();
            $table->enum('submission_type', ['prequalification', 'preapproval', 'application', 'lead'])
                  ->default('application');
            // OnDeck status stages: pending | submitted | underwriting | approved | closing | funded | declined | other | cannot_contact
            $table->string('status', 60)->default('pending')->index();
            $table->text('status_note')->nullable();
            $table->json('raw_response')->nullable();
            $table->unsignedBigInteger('submitted_by')->nullable();
            $table->timestamps();

            $table->index(['lead_id', 'lender_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lender_applications');
    }
}
