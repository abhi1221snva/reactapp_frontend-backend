<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * rvm_tenant_flags — per-tenant RVM v2 pipeline mode.
 *
 * Phase 4 shadow-mode / cutover control. One row per client_id; no row
 * means "legacy" (the safe default). Looked up on every legacy drop via
 * RvmFeatureFlagService with a 60s Redis cache so this table is never a
 * hot path.
 *
 * Modes:
 *   legacy   — default. Legacy pipeline only. Zero new-pipeline side effects.
 *   shadow   — Legacy pipeline still authoritative. The new compliance and
 *              router run in a dry pass and write to rvm_shadow_log so
 *              operators can diff outcomes before flipping further.
 *   dry_run  — New pipeline runs for real (wallet, events, webhooks) but
 *              provider delivery is replaced with the mock provider. Used
 *              by tenants who want to validate webhook signatures without
 *              spending real money on drops.
 *   live     — Cutover complete. Legacy path forwards into the new pipeline
 *              and SendRvmJob short-circuits.
 */
class CreateRvmTenantFlagsTable extends Migration
{
    public function up()
    {
        Schema::connection('master')->create('rvm_tenant_flags', function (Blueprint $table) {
            $table->unsignedInteger('client_id')->primary();

            $table->enum('pipeline_mode', ['legacy', 'shadow', 'dry_run', 'live'])
                ->default('legacy');

            $table->unsignedInteger('enabled_by_user_id')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index('pipeline_mode', 'idx_rvm_tenant_flags_mode');
        });
    }

    public function down()
    {
        Schema::connection('master')->dropIfExists('rvm_tenant_flags');
    }
}
