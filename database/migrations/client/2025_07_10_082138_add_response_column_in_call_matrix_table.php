<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddResponseColumnInCallMatrixTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('call_matrix_report', function (Blueprint $table) {
            if (!Schema::hasColumn('call_matrix_report', 'response_data')) $table->json('response_data')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('call_matrix', function (Blueprint $table) {
            //
        });
    }
}
