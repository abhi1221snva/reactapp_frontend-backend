<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add submission_type column to crm_lender_submissions.
 *
 * Run per-client:
 *   php artisan migrate --path=database/migrations/client/2026_03_28_000001_add_submission_type_to_crm_lender_submissions.php
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_lender_submissions', function (Blueprint $table) {
            // 'normal' = email-based submission, 'api' = lender API dispatch
            $table->string('submission_type', 10)
                  ->default('normal')
                  ->after('submission_status')
                  ->comment('normal=email, api=lender-api-dispatch');
        });
    }

    public function down(): void
    {
        Schema::table('crm_lender_submissions', function (Blueprint $table) {
            $table->dropColumn('submission_type');
        });
    }
};
