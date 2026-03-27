<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds required_in JSON column to crm_labels.
 *
 * required_in is a JSON array of form contexts in which this field is required:
 *   null                              → fall back to legacy `required` boolean (backward compat)
 *   []                                → not required in any context
 *   ["system"]                        → required only in the CRM system form
 *   ["affiliate"]                     → required only in the affiliate apply form
 *   ["merchant"]                      → required only in the merchant portal
 *   ["system","affiliate"]            → required in system + affiliate
 *   ["system","affiliate","merchant"] → required everywhere
 */
class AddRequiredInToCrmLabels extends Migration
{
    public function up(): void
    {
        Schema::table('crm_labels', function (Blueprint $table) {
            $table->json('required_in')
                  ->nullable()
                  ->after('apply_to')
                  ->comment('null=fallback to required bool | JSON array of: system, affiliate, merchant');
        });
    }

    public function down(): void
    {
        Schema::table('crm_labels', function (Blueprint $table) {
            $table->dropColumn('required_in');
        });
    }
}
