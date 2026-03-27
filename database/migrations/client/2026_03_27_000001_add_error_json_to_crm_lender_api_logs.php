<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds structured error analysis columns to crm_lender_api_logs.
 *
 * error_json       — parsed errors array from ErrorParserService
 * fix_suggestions  — enriched errors with auto-fix values from FixSuggestionService
 * is_fixable       — true when at least one error has a known, actionable fix_type
 */
class AddErrorJsonToCrmLenderApiLogs extends Migration
{
    public function up(): void
    {
        Schema::table('crm_lender_api_logs', function (Blueprint $table) {
            $table->json('error_json')
                  ->nullable()
                  ->after('error_message')
                  ->comment('Structured parsed errors (ErrorParserService)');

            $table->json('fix_suggestions')
                  ->nullable()
                  ->after('error_json')
                  ->comment('Enriched errors with auto-fix values (FixSuggestionService)');

            $table->boolean('is_fixable')
                  ->default(false)
                  ->after('fix_suggestions')
                  ->comment('True when >= 1 error has a known, actionable fix type');
        });
    }

    public function down(): void
    {
        Schema::table('crm_lender_api_logs', function (Blueprint $table) {
            $table->dropColumn(['error_json', 'fix_suggestions', 'is_fixable']);
        });
    }
}
