<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add 'failed' to submission_status enum and add error_messages JSON column
 * for structured API error storage on crm_lender_submissions.
 *
 * Run per-client:
 *   php artisan migrate --path=database/migrations/client/2026_03_31_000001_add_failed_status_and_error_messages_to_crm_lender_submissions.php
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('crm_lender_submissions')) {
            return;
        }

        // Add 'failed' to submission_status enum
        DB::statement("ALTER TABLE `crm_lender_submissions` MODIFY `submission_status` ENUM('pending','submitted','failed','partial','viewed','approved','declined','no_response') NOT NULL DEFAULT 'pending'");

        // Add error_messages JSON column for structured error storage
        if (!Schema::hasColumn('crm_lender_submissions', 'error_messages')) {
            Schema::table('crm_lender_submissions', function ($table) {
                $table->json('error_messages')->nullable()->after('api_error')
                    ->comment('Structured API errors: [{field, label, message, fix_type}]');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('crm_lender_submissions')) {
            return;
        }

        // Revert enum — change any 'failed' rows back to 'pending' first
        DB::table('crm_lender_submissions')
            ->where('submission_status', 'failed')
            ->update(['submission_status' => 'pending']);

        DB::statement("ALTER TABLE `crm_lender_submissions` MODIFY `submission_status` ENUM('pending','submitted','partial','viewed','approved','declined','no_response') NOT NULL DEFAULT 'pending'");

        if (Schema::hasColumn('crm_lender_submissions', 'error_messages')) {
            Schema::table('crm_lender_submissions', function ($table) {
                $table->dropColumn('error_messages');
            });
        }
    }
};
