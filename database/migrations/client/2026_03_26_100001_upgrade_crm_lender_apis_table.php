<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Upgrade crm_lender_apis from a flat credential bag into a scalable,
 * lender-agnostic API configuration table.
 *
 * Backward-compatible: old columns (username, password, api_key, url, type,
 * bittyadvance_label, sales_rep_email, partner_api_key, auth_url, client_id)
 * are kept so the legacy SendLeadByLenderApi job continues to work during the
 * transition period.
 */
class UpgradeCrmLenderApisTable extends Migration
{
    public function up(): void
    {
        Schema::table('crm_lender_apis', function (Blueprint $table) {

            // ── Human-readable name for this API configuration ─────────────────
            $table->string('api_name')->nullable()->after('id');

            // ── Auth strategy ──────────────────────────────────────────────────
            // bearer   → Authorization: Bearer <token>
            // basic    → Authorization: Basic base64(user:pass)
            // api_key  → custom header / query param carrying a single key
            // oauth2   → client_credentials flow: fetch token, then use Bearer
            // none     → no authentication required
            $table->enum('auth_type', ['bearer', 'basic', 'api_key', 'oauth2', 'none'])
                  ->default('none')
                  ->after('api_name');

            // ── All auth credentials in one JSON blob ──────────────────────────
            // bearer  → { "token": "..." }
            // basic   → { "username": "...", "password": "..." }
            // api_key → { "key": "...", "header_name": "X-Api-Key", "in": "header|query" }
            // oauth2  → { "token_url": "...", "client_id": "...", "client_secret": "...", "scope": "..." }
            $table->json('auth_credentials')->nullable()->after('auth_type');

            // ── Request targeting ──────────────────────────────────────────────
            $table->string('base_url')->nullable()->after('auth_credentials');
            $table->string('endpoint_path')->nullable()->after('base_url');
            $table->enum('request_method', ['GET', 'POST', 'PUT', 'PATCH'])->default('POST')->after('endpoint_path');

            // ── Default headers sent with every request ────────────────────────
            // e.g. { "Content-Type": "application/json", "Accept": "application/json" }
            $table->json('default_headers')->nullable()->after('request_method');

            // ── Dynamic payload mapping (CRM field_key → lender JSON path) ─────
            // Replaces the column-per-lender crm_lender_apis_label_setting table.
            // Example:
            // {
            //   "business_name":    "business.name",
            //   "owner_first_name": "owners.0.firstName",
            //   "ein":              "business.taxID"
            // }
            // Supports dot-notation for nested structures and array indices.
            $table->json('payload_mapping')->nullable()->after('default_headers');

            // ── Response field mapping ─────────────────────────────────────────
            // Tells the service how to extract useful data from the API response.
            // Example:
            // {
            //   "id_field":     "data.applicationId",
            //   "status_field": "data.status",
            //   "message_field": "error.message"
            // }
            $table->json('response_mapping')->nullable()->after('payload_mapping');

            // ── Reliability settings ───────────────────────────────────────────
            $table->unsignedTinyInteger('retry_attempts')->default(3)->after('response_mapping');
            $table->unsignedSmallInteger('timeout_seconds')->default(30)->after('retry_attempts');

            // ── Lifecycle ──────────────────────────────────────────────────────
            $table->boolean('status')->default(true)->after('timeout_seconds');
            $table->text('notes')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('crm_lender_apis', function (Blueprint $table) {
            $table->dropColumn([
                'api_name', 'auth_type', 'auth_credentials',
                'base_url', 'endpoint_path', 'request_method',
                'default_headers', 'payload_mapping', 'response_mapping',
                'retry_attempts', 'timeout_seconds', 'status', 'notes',
            ]);
        });
    }
}
