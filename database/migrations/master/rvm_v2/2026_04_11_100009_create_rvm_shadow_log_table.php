<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * rvm_shadow_log — observability trail for RVM v2 shadow mode.
 *
 * Each row records what the NEW pipeline WOULD have done for a legacy
 * drop that just flowed through SendRvmJob. Writing here is best-effort
 * only: any failure in the shadow hook MUST NOT break the legacy flow,
 * so RvmShadowService swallows errors after logging.
 *
 * Retention: expected to churn. Operators diff for divergences via
 * `php artisan rvm:shadow-report` then truncate old rows.
 */
class CreateRvmShadowLogTable extends Migration
{
    public function up()
    {
        Schema::connection('master')->create('rvm_shadow_log', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('client_id');

            // Legacy CDR id this shadow row corresponds to, when known.
            $table->unsignedInteger('legacy_rvm_cdr_log_id')->nullable();

            // Drop inputs (already sanitized by the legacy path).
            $table->string('phone_e164', 32);
            $table->string('caller_id', 32)->nullable();
            $table->dateTime('legacy_dispatched_at');

            // What the NEW pipeline WOULD have done.
            $table->boolean('would_dispatch')->default(false);
            $table->string('would_provider', 32)->nullable();
            $table->unsignedInteger('would_cost_cents')->nullable();
            $table->text('would_reject_reason')->nullable();

            // Per-row divergence flags (compliance_diff, provider_diff, ...).
            $table->json('divergence_flags')->nullable();

            // Raw legacy payload snapshot for post-hoc debugging.
            $table->json('legacy_payload')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['client_id', 'created_at'], 'idx_rvm_shadow_client_time');
            $table->index('legacy_rvm_cdr_log_id', 'idx_rvm_shadow_legacy_id');
            $table->index(['client_id', 'would_dispatch', 'created_at'], 'idx_rvm_shadow_dispatch');
        });
    }

    public function down()
    {
        Schema::connection('master')->dropIfExists('rvm_shadow_log');
    }
}
