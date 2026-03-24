<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddFilePathToCrmDocumentsTable extends Migration
{
    public function up()
    {
        if (!Schema::hasColumn('crm_documents', 'file_path')) {
            Schema::table('crm_documents', function (Blueprint $table) {
                $table->string('file_path', 1000)->nullable()->after('document_type');
            });
        }
        if (!Schema::hasColumn('crm_documents', 'uploaded_by')) {
            Schema::table('crm_documents', function (Blueprint $table) {
                $table->unsignedInteger('uploaded_by')->nullable()->after('file_size');
            });
        }
        if (!Schema::hasColumn('crm_documents', 'deleted_at')) {
            Schema::table('crm_documents', function (Blueprint $table) {
                $table->softDeletes();
            });
        }
    }

    public function down()
    {
        Schema::table('crm_documents', function (Blueprint $table) {
            $table->dropColumn(['file_path', 'uploaded_by', 'deleted_at']);
        });
    }
}
