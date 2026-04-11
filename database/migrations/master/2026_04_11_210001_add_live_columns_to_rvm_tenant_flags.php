<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add RVM v2 live-mode configuration columns to rvm_tenant_flags.
 *
 * Phase 5c cutover: once a tenant is ready to leave dry_run and actually
 * dispatch voicemails to real carriers, these columns tell the divert
 * service which provider to route through and impose an optional daily
 * safety cap so a misconfiguration can't blow a full wallet budget in an
 * hour.
 *
 *   live_provider    — carrier name (e.g. 'twilio', 'plivo', 'slybroadcast',
 *                      or 'mock' for staging). Must be non-NULL before
 *                      RvmDivertService will divert in live mode.
 *   live_daily_cap   — max diverted drops per UTC day for this tenant.
 *                      NULL = no cap. Once the count for today ≥ cap the
 *                      divert service skips with reason=live_daily_cap_reached
 *                      and the legacy pipeline (if still wired) takes over
 *                      for the overflow.
 *   live_enabled_at  — UTC timestamp of the most recent dry_run → live
 *                      flip. Audit-only; used by reporting dashboards.
 *
 * All columns are nullable so tenants still in shadow/dry_run/legacy can
 * keep their existing rvm_tenant_flags row unchanged.
 */
class AddLiveColumnsToRvmTenantFlags extends Migration
{
    public function up(): void
    {
        if (!Schema::connection('master')->hasTable('rvm_tenant_flags')) {
            return;
        }

        Schema::connection('master')->table('rvm_tenant_flags', function (Blueprint $table) {
            if (!Schema::connection('master')->hasColumn('rvm_tenant_flags', 'live_provider')) {
                $table->string('live_provider', 32)->nullable()->after('pipeline_mode');
            }
            if (!Schema::connection('master')->hasColumn('rvm_tenant_flags', 'live_daily_cap')) {
                $table->unsignedInteger('live_daily_cap')->nullable()->after('live_provider');
            }
            if (!Schema::connection('master')->hasColumn('rvm_tenant_flags', 'live_enabled_at')) {
                $table->timestamp('live_enabled_at')->nullable()->after('live_daily_cap');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::connection('master')->hasTable('rvm_tenant_flags')) {
            return;
        }

        Schema::connection('master')->table('rvm_tenant_flags', function (Blueprint $table) {
            $table->dropColumn(['live_provider', 'live_daily_cap', 'live_enabled_at']);
        });
    }
}
