<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add RVM v2 divert tracking columns to rvm_cdr_log.
 *
 * Phase 5b cutover mechanism: when a tenant is flipped to dry_run (or
 * later live) mode, SendRvmJob diverts the legacy payload into the v2
 * RvmDropService and records the resulting rvm_drops.id here so
 * operators can audit the legacy→v2 correlation and the divert hook
 * can skip rows that have already been processed.
 *
 *   v2_drop_id   — ULID of the created rvm_drops row (26 chars)
 *   divert_mode  — which tenant mode produced the divert: 'dry_run' | 'live'
 *   diverted_at  — when the divert happened (UTC)
 *
 * All three are nullable: legacy-only rows keep them NULL. A non-NULL
 * diverted_at is the authoritative "this row was handled by v2" marker —
 * RvmDivertService uses it to guarantee at-most-once divert per cdr row.
 */
class AddDivertColumnsToRvmCdrLog extends Migration
{
    public function up(): void
    {
        if (!Schema::connection('master')->hasTable('rvm_cdr_log')) {
            return;
        }

        Schema::connection('master')->table('rvm_cdr_log', function (Blueprint $table) {
            if (!Schema::connection('master')->hasColumn('rvm_cdr_log', 'v2_drop_id')) {
                $table->string('v2_drop_id', 26)->nullable()->after('voicemail_id');
            }
            if (!Schema::connection('master')->hasColumn('rvm_cdr_log', 'divert_mode')) {
                $table->string('divert_mode', 16)->nullable()->after('v2_drop_id');
            }
            if (!Schema::connection('master')->hasColumn('rvm_cdr_log', 'diverted_at')) {
                $table->timestamp('diverted_at')->nullable()->after('divert_mode');
            }
        });

        // Separate schema call so the index can reference the column added above.
        Schema::connection('master')->table('rvm_cdr_log', function (Blueprint $table) {
            $table->index('v2_drop_id', 'idx_rvm_cdr_log_v2_drop_id');
            $table->index('diverted_at', 'idx_rvm_cdr_log_diverted_at');
        });
    }

    public function down(): void
    {
        if (!Schema::connection('master')->hasTable('rvm_cdr_log')) {
            return;
        }

        Schema::connection('master')->table('rvm_cdr_log', function (Blueprint $table) {
            $table->dropIndex('idx_rvm_cdr_log_v2_drop_id');
            $table->dropIndex('idx_rvm_cdr_log_diverted_at');
        });

        Schema::connection('master')->table('rvm_cdr_log', function (Blueprint $table) {
            $table->dropColumn(['v2_drop_id', 'divert_mode', 'diverted_at']);
        });
    }
}
