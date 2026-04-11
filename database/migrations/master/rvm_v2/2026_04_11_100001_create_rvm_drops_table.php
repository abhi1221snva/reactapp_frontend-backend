<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * rvm_drops — single source of truth for every voicemail drop in RVM v2.
 *
 * This table is ADDITIVE. The legacy rvm_cdr_log table is untouched and
 * continues to serve the old pipeline until tenants are migrated to v2.
 *
 * See docs/rvm-v2-architecture.md §5.1.
 */
class CreateRvmDropsTable extends Migration
{
    public function up()
    {
        Schema::connection('master')->create('rvm_drops', function (Blueprint $table) {
            // ULID primary key — sortable, URL-safe, 26 chars.
            $table->char('id', 26)->primary();

            $table->unsignedInteger('client_id');
            $table->unsignedInteger('user_id')->nullable();          // portal drops
            $table->unsignedBigInteger('api_key_id')->nullable();    // external API drops
            $table->char('campaign_id', 26)->nullable();
            $table->char('batch_id', 26)->nullable();                // bulk import group

            // Idempotency: scoped per-tenant + 24h TTL enforced in Redis.
            $table->string('idempotency_key', 64)->nullable();

            // Recipient + sender
            $table->string('phone_e164', 16);
            $table->string('caller_id', 16);
            $table->unsignedBigInteger('voice_template_id');

            // Queue routing
            $table->enum('priority', ['instant', 'normal', 'bulk'])->default('normal');

            // Lifecycle
            $table->enum('status', [
                'queued',
                'deferred',      // compliance window not open yet
                'dispatching',   // provider call in flight
                'delivered',
                'failed',
                'cancelled',
                'expired',       // lived past max lifetime (24h default)
            ])->default('queued');
            $table->dateTime('deferred_until')->nullable();

            // Provider details (filled at dispatch)
            $table->string('provider', 32)->nullable();
            $table->string('provider_message_id', 128)->nullable();
            $table->integer('provider_cost_cents')->nullable();

            // Wallet
            $table->char('reservation_id', 26)->nullable();
            $table->integer('cost_cents');

            // Tenant callback URL (optional, overrides tenant-default webhook)
            $table->string('callback_url', 512)->nullable();

            // Free-form passthrough for tenants (lead_id, crm_ref, etc.)
            $table->json('metadata')->nullable();

            // Timestamps of state transitions (queryable; separate from created/updated)
            $table->dateTime('scheduled_at')->nullable();
            $table->dateTime('dispatched_at')->nullable();
            $table->dateTime('delivered_at')->nullable();
            $table->dateTime('failed_at')->nullable();

            // Retry bookkeeping (updated atomically via UPDATE ... SET tries = tries + 1)
            $table->unsignedTinyInteger('tries')->default(0);
            $table->string('last_error', 500)->nullable();

            $table->timestamps();

            // ── Indexes ────────────────────────────────────────────────────
            // Per-tenant idempotency: the *only* way to dedupe API calls safely.
            $table->unique(['client_id', 'idempotency_key'], 'uk_rvm_drops_idem');

            // Dashboard: "show me queued/failed drops for client X, newest first"
            $table->index(['client_id', 'status', 'created_at'], 'idx_rvm_drops_client_status_created');

            // Campaign stats
            $table->index(['campaign_id', 'status'], 'idx_rvm_drops_campaign_status');

            // Provider callback lookup (Twilio/Plivo POST message_id; we resolve back to drop)
            $table->index(['provider', 'provider_message_id'], 'idx_rvm_drops_provider_msg');

            // Scheduler sweep: "find all drops that are deferred past their window"
            $table->index(['status', 'deferred_until'], 'idx_rvm_drops_deferred');

            // Velocity + DNC cross-checks
            $table->index('phone_e164', 'idx_rvm_drops_phone');
        });
    }

    public function down()
    {
        Schema::connection('master')->dropIfExists('rvm_drops');
    }
}
