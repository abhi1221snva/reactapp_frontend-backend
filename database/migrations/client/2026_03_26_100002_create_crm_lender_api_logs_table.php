<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Structured audit log for every outbound lender API call.
 *
 * One row per API request attempt. Replaces the inconsistent ApiLog usage
 * scattered throughout SendLeadByLenderApi.
 */
class CreateCrmLenderApiLogsTable extends Migration
{
    public function up(): void
    {
        Schema::create('crm_lender_api_logs', function (Blueprint $table) {
            $table->id();

            // Link to the API config used
            $table->unsignedBigInteger('crm_lender_api_id')->nullable()->index();

            // Context
            $table->unsignedBigInteger('lead_id')->index();
            $table->unsignedBigInteger('lender_id')->index();
            $table->unsignedBigInteger('user_id')->nullable();

            // Outbound request
            $table->string('request_url');
            $table->string('request_method', 10)->default('POST');
            $table->json('request_headers')->nullable();
            $table->longText('request_payload')->nullable();

            // Response
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->longText('response_body')->nullable();

            // Outcome
            // success  → 2xx received, response_mapping parsed OK
            // http_error → non-2xx response
            // timeout  → request timed out
            // error    → exception thrown (network, config, etc.)
            $table->enum('status', ['success', 'http_error', 'timeout', 'error'])->default('error')->index();
            $table->text('error_message')->nullable();

            // Performance
            $table->unsignedInteger('duration_ms')->nullable()->comment('Round-trip time in milliseconds');

            // Attempt tracking (for retries)
            $table->unsignedTinyInteger('attempt')->default(1);

            $table->timestamp('created_at')->useCurrent();

            // Index for common queries: logs for a lead, or logs for a lender
            $table->index(['lead_id', 'lender_id']);
            $table->index(['crm_lender_api_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_lender_api_logs');
    }
}
