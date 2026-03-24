<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddValidationRulesToCrmLabels extends Migration
{
    public function up()
    {
        Schema::table('crm_labels', function (Blueprint $table) {
            $table->json('validation_rules')->nullable()->after('conditions');
        });
    }

    public function down()
    {
        Schema::table('crm_labels', function (Blueprint $table) {
            $table->dropColumn('validation_rules');
        });
    }
}
