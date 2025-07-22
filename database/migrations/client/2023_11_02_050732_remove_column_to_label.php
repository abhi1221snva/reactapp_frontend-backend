<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class RemoveColumnToLabel extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('label', function (Blueprint $table) {
            $table->dropColumn('edit_mode');
            $table->dropColumn('merchant_required');
            $table->dropColumn('view_on_lead');
            $table->dropColumn('custom_values');
            $table->dropColumn('label_title_url');
            $table->dropColumn('data_type');
            $table->dropColumn('required');
            $table->dropColumn('display_order');
            $table->dropColumn('column_name');
            $table->dropColumn('status');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('label', function (Blueprint $table) {
            
        });
    }
}
