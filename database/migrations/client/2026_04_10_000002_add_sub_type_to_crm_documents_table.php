<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddSubTypeToCrmDocumentsTable extends Migration
{
    public function up()
    {
        if (!Schema::hasColumn('crm_documents', 'sub_type')) {
            Schema::table('crm_documents', function (Blueprint $table) {
                $table->string('sub_type', 100)->nullable()->after('document_type')->index();
            });
        }
    }

    public function down()
    {
        if (Schema::hasColumn('crm_documents', 'sub_type')) {
            Schema::table('crm_documents', function (Blueprint $table) {
                $table->dropColumn('sub_type');
            });
        }
    }
}
