<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds re-submission and document upload configuration columns to crm_lender_apis,
 * and adds API error / document upload status tracking to crm_lender_submissions.
 */
class AddResubmitAndDocumentUploadToCrmLenderApis extends Migration
{
    public function up(): void
    {
        // ── crm_lender_apis — new config columns ───────────────────────────────
        if (Schema::hasTable('crm_lender_apis')) {
            Schema::table('crm_lender_apis', function (Blueprint $table) {
                if (!Schema::hasColumn('crm_lender_apis', 'required_fields')) {
                    $table->json('required_fields')->nullable()->after('response_mapping')
                        ->comment('Array of CRM field_keys that must be non-empty before sending API request');
                }
                if (!Schema::hasColumn('crm_lender_apis', 'resubmit_method')) {
                    $table->string('resubmit_method', 10)->nullable()->after('request_method')
                        ->comment('HTTP method for re-submission (PUT or PATCH)');
                }
                if (!Schema::hasColumn('crm_lender_apis', 'resubmit_endpoint_path')) {
                    $table->string('resubmit_endpoint_path', 500)->nullable()->after('resubmit_method')
                        ->comment('URL path for re-submission, supports {id} placeholder e.g. /application/{id}');
                }
                if (!Schema::hasColumn('crm_lender_apis', 'document_upload_enabled')) {
                    $table->boolean('document_upload_enabled')->default(false)->after('resubmit_endpoint_path')
                        ->comment('Whether to upload documents after successful API submission');
                }
                if (!Schema::hasColumn('crm_lender_apis', 'document_upload_endpoint')) {
                    $table->string('document_upload_endpoint', 500)->nullable()->after('document_upload_enabled')
                        ->comment('URL path for document upload, supports {id} placeholder e.g. /application/{id}/documents');
                }
                if (!Schema::hasColumn('crm_lender_apis', 'document_upload_method')) {
                    $table->string('document_upload_method', 10)->default('POST')->after('document_upload_endpoint')
                        ->comment('HTTP method for document upload (POST or PUT)');
                }
                if (!Schema::hasColumn('crm_lender_apis', 'document_upload_field_name')) {
                    $table->string('document_upload_field_name', 100)->default('file')->after('document_upload_method')
                        ->comment('Multipart field name for the file in document upload requests');
                }
            });
        }

        // ── crm_lender_submissions — status + error tracking columns ───────────
        if (Schema::hasTable('crm_lender_submissions')) {
            Schema::table('crm_lender_submissions', function (Blueprint $table) {
                if (!Schema::hasColumn('crm_lender_submissions', 'api_error')) {
                    $table->text('api_error')->nullable()->after('notes')
                        ->comment('Last API error message for UI display (pre-flight validation, HTTP errors, etc.)');
                }
                if (!Schema::hasColumn('crm_lender_submissions', 'doc_upload_status')) {
                    $table->enum('doc_upload_status', ['none', 'success', 'partial', 'failed'])
                        ->default('none')->after('api_error')
                        ->comment('Document upload outcome: none=no docs required, partial=some failed');
                }
                if (!Schema::hasColumn('crm_lender_submissions', 'doc_upload_notes')) {
                    $table->text('doc_upload_notes')->nullable()->after('doc_upload_status')
                        ->comment('Summary of document upload results e.g. "Uploaded: 2 / Failed: 1"');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('crm_lender_apis')) {
            Schema::table('crm_lender_apis', function (Blueprint $table) {
                $cols = [
                    'required_fields', 'resubmit_method', 'resubmit_endpoint_path',
                    'document_upload_enabled', 'document_upload_endpoint',
                    'document_upload_method', 'document_upload_field_name',
                ];
                foreach ($cols as $col) {
                    if (Schema::hasColumn('crm_lender_apis', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }

        if (Schema::hasTable('crm_lender_submissions')) {
            Schema::table('crm_lender_submissions', function (Blueprint $table) {
                foreach (['api_error', 'doc_upload_status', 'doc_upload_notes'] as $col) {
                    if (Schema::hasColumn('crm_lender_submissions', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
}
