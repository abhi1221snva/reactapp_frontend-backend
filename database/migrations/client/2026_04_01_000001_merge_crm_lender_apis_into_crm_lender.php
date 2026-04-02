<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Merges all crm_lender_apis columns into crm_lender (single table).
 * Copies existing API config data, then renames crm_lender_apis to backup.
 */
class MergeCrmLenderApisIntoCrmLender extends Migration
{
    public function up(): void
    {
        // ── 1. Add API config columns to crm_lender ─────────────────────────────
        Schema::table('crm_lender', function (Blueprint $table) {
            // Legacy credentials (renamed to avoid ambiguity)
            if (!Schema::hasColumn('crm_lender', 'api_username'))  $table->string('api_username', 255)->nullable()->after('lender_api_type');
            if (!Schema::hasColumn('crm_lender', 'api_password'))  $table->string('api_password', 255)->nullable()->after('api_username');
            if (!Schema::hasColumn('crm_lender', 'api_key'))       $table->string('api_key', 255)->nullable()->after('api_password');
            if (!Schema::hasColumn('crm_lender', 'api_url'))       $table->string('api_url', 255)->nullable()->after('api_key');
            if (!Schema::hasColumn('crm_lender', 'sales_rep_email')) $table->string('sales_rep_email', 255)->nullable()->after('api_url');
            if (!Schema::hasColumn('crm_lender', 'partner_api_key')) $table->string('partner_api_key', 255)->nullable()->after('sales_rep_email');
            if (!Schema::hasColumn('crm_lender', 'api_client_id')) $table->string('api_client_id', 255)->nullable()->after('partner_api_key');
            if (!Schema::hasColumn('crm_lender', 'auth_url'))      $table->string('auth_url', 255)->nullable()->after('api_client_id');

            // New-style config
            if (!Schema::hasColumn('crm_lender', 'api_name'))      $table->string('api_name', 255)->nullable()->after('auth_url');
            if (!Schema::hasColumn('crm_lender', 'auth_type'))     $table->enum('auth_type', ['bearer','basic','api_key','oauth2','none'])->default('none')->after('api_name');
            if (!Schema::hasColumn('crm_lender', 'auth_credentials')) $table->json('auth_credentials')->nullable()->after('auth_type');
            if (!Schema::hasColumn('crm_lender', 'base_url'))      $table->string('base_url', 255)->nullable()->after('auth_credentials');
            if (!Schema::hasColumn('crm_lender', 'endpoint_path')) $table->string('endpoint_path', 500)->nullable()->after('base_url');
            if (!Schema::hasColumn('crm_lender', 'request_method')) $table->enum('request_method', ['GET','POST','PUT','PATCH'])->default('POST')->after('endpoint_path');
            if (!Schema::hasColumn('crm_lender', 'default_headers')) $table->json('default_headers')->nullable()->after('request_method');
            if (!Schema::hasColumn('crm_lender', 'payload_mapping')) $table->json('payload_mapping')->nullable()->after('default_headers');
            if (!Schema::hasColumn('crm_lender', 'response_mapping')) $table->json('response_mapping')->nullable()->after('payload_mapping');
            if (!Schema::hasColumn('crm_lender', 'required_fields')) $table->json('required_fields')->nullable()->after('response_mapping');
            if (!Schema::hasColumn('crm_lender', 'retry_attempts')) $table->tinyInteger('retry_attempts')->unsigned()->default(3)->after('required_fields');
            if (!Schema::hasColumn('crm_lender', 'timeout_seconds')) $table->smallInteger('timeout_seconds')->unsigned()->default(30)->after('retry_attempts');
            if (!Schema::hasColumn('crm_lender', 'api_notes'))     $table->text('api_notes')->nullable()->after('timeout_seconds');

            // Re-submission config
            if (!Schema::hasColumn('crm_lender', 'resubmit_method')) $table->string('resubmit_method', 10)->nullable()->after('api_notes');
            if (!Schema::hasColumn('crm_lender', 'resubmit_endpoint_path')) $table->string('resubmit_endpoint_path', 500)->nullable()->after('resubmit_method');

            // Document upload config
            if (!Schema::hasColumn('crm_lender', 'document_upload_enabled')) $table->boolean('document_upload_enabled')->default(false)->after('resubmit_endpoint_path');
            if (!Schema::hasColumn('crm_lender', 'document_upload_endpoint')) $table->string('document_upload_endpoint', 500)->nullable()->after('document_upload_enabled');
            if (!Schema::hasColumn('crm_lender', 'document_upload_method')) $table->string('document_upload_method', 10)->default('POST')->after('document_upload_endpoint');
            if (!Schema::hasColumn('crm_lender', 'document_upload_field_name')) $table->string('document_upload_field_name', 100)->default('file')->after('document_upload_method');
        });

        // ── 2. Copy data from crm_lender_apis → crm_lender ─────────────────────
        if (Schema::hasTable('crm_lender_apis')) {
            DB::statement("
                UPDATE crm_lender l
                INNER JOIN crm_lender_apis a ON a.crm_lender_id = l.id
                SET
                    l.api_username            = a.username,
                    l.api_password            = a.password,
                    l.api_key                 = a.api_key,
                    l.api_url                 = a.url,
                    l.sales_rep_email         = a.sales_rep_email,
                    l.partner_api_key         = a.partner_api_key,
                    l.api_client_id           = a.client_id,
                    l.auth_url                = a.auth_url,
                    l.api_name                = a.api_name,
                    l.auth_type               = a.auth_type,
                    l.auth_credentials        = a.auth_credentials,
                    l.base_url                = a.base_url,
                    l.endpoint_path           = a.endpoint_path,
                    l.request_method          = a.request_method,
                    l.default_headers         = a.default_headers,
                    l.payload_mapping         = a.payload_mapping,
                    l.response_mapping        = a.response_mapping,
                    l.required_fields         = a.required_fields,
                    l.retry_attempts          = a.retry_attempts,
                    l.timeout_seconds         = a.timeout_seconds,
                    l.api_notes               = a.notes,
                    l.resubmit_method         = a.resubmit_method,
                    l.resubmit_endpoint_path  = a.resubmit_endpoint_path,
                    l.document_upload_enabled = a.document_upload_enabled,
                    l.document_upload_endpoint = a.document_upload_endpoint,
                    l.document_upload_method  = a.document_upload_method,
                    l.document_upload_field_name = a.document_upload_field_name,
                    l.lender_api_type         = COALESCE(NULLIF(l.lender_api_type, ''), a.type)
            ");

            // ── 3. Rename old table as backup ───────────────────────────────────
            Schema::rename('crm_lender_apis', 'crm_lender_apis_backup');
        }
    }

    public function down(): void
    {
        // Restore backup table
        if (Schema::hasTable('crm_lender_apis_backup') && !Schema::hasTable('crm_lender_apis')) {
            Schema::rename('crm_lender_apis_backup', 'crm_lender_apis');
        }

        // Drop added columns
        Schema::table('crm_lender', function (Blueprint $table) {
            $cols = [
                'api_username', 'api_password', 'api_key', 'api_url',
                'sales_rep_email', 'partner_api_key', 'api_client_id', 'auth_url',
                'api_name', 'auth_type', 'auth_credentials', 'base_url', 'endpoint_path',
                'request_method', 'default_headers', 'payload_mapping', 'response_mapping',
                'required_fields', 'retry_attempts', 'timeout_seconds', 'api_notes',
                'resubmit_method', 'resubmit_endpoint_path',
                'document_upload_enabled', 'document_upload_endpoint',
                'document_upload_method', 'document_upload_field_name',
            ];
            foreach ($cols as $col) {
                if (Schema::hasColumn('crm_lender', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
}
