<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add apply_to column to crm_labels.
 *
 * Controls which public-facing forms a field is visible on and required in:
 *   null      → no restriction — shown on all forms (original behaviour)
 *   affiliate → affiliate apply form only
 *   merchant  → merchant portal form only
 *   both      → both affiliate and merchant forms
 *
 * The internal CRM (system) form always shows every field regardless of apply_to.
 * The `required` column still governs whether the field is mandatory wherever it appears.
 *
 * Run per client DB:
 *   php artisan migrate --path=database/migrations/client/2026_03_26_000001_add_apply_to_to_crm_labels.php
 */
class AddApplyToToCrmLabels extends Migration
{
    public function up(): void
    {
        Schema::table('crm_labels', function (Blueprint $table) {
            $table->string('apply_to', 20)
                  ->nullable()
                  ->after('required')
                  ->comment('null=all forms | affiliate | merchant | both');
        });
    }

    public function down(): void
    {
        Schema::table('crm_labels', function (Blueprint $table) {
            $table->dropColumn('apply_to');
        });
    }
}
