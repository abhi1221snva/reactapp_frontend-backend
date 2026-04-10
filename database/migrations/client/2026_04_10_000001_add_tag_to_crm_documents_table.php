<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddTagToCrmDocumentsTable extends Migration
{
    public function up()
    {
        if (!Schema::hasColumn('crm_documents', 'tag')) {
            Schema::table('crm_documents', function (Blueprint $table) {
                $table->string('tag', 50)->nullable()->after('document_type')->index();
            });
        }
    }

    public function down()
    {
        if (Schema::hasColumn('crm_documents', 'tag')) {
            Schema::table('crm_documents', function (Blueprint $table) {
                $table->dropColumn('tag');
            });
        }
    }
}
