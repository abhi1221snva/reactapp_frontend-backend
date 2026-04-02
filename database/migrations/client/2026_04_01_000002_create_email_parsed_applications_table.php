<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateEmailParsedApplicationsTable extends Migration
{
    public function up(): void
    {
        Schema::create('email_parsed_applications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('attachment_id');
            $table->string('gmail_message_id', 255);
            $table->unsignedBigInteger('user_id');
            $table->string('business_name', 500)->nullable();
            $table->string('business_dba', 500)->nullable();
            $table->string('owner_first_name', 255)->nullable();
            $table->string('owner_last_name', 255)->nullable();
            $table->string('owner_email', 255)->nullable();
            $table->string('owner_phone', 50)->nullable();
            $table->string('owner_ssn_last4', 4)->nullable();
            $table->string('business_ein', 20)->nullable();
            $table->string('business_address', 500)->nullable();
            $table->string('business_city', 255)->nullable();
            $table->string('business_state', 100)->nullable();
            $table->string('business_zip', 20)->nullable();
            $table->string('business_type', 255)->nullable();
            $table->decimal('annual_revenue', 14, 2)->nullable();
            $table->decimal('monthly_revenue', 14, 2)->nullable();
            $table->decimal('requested_amount', 14, 2)->nullable();
            $table->text('use_of_funds')->nullable();
            $table->string('time_in_business', 100)->nullable();
            $table->decimal('confidence_score', 5, 2)->nullable();
            $table->json('raw_extraction')->nullable();
            $table->string('extraction_model', 100)->default('claude-sonnet-4-20250514');
            $table->enum('status', ['parsed', 'review', 'accepted', 'rejected', 'lead_created'])->default('parsed');
            $table->unsignedBigInteger('lead_id')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index('attachment_id');
            $table->index('gmail_message_id');
            $table->index('status');
            $table->index('lead_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_parsed_applications');
    }
}
