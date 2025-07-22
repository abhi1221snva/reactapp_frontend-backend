<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AlterColumnInRinglessCdrArchiveTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('ringless_cdr_archive', function (Blueprint $table) {
            \DB::statement("ALTER TABLE ringless_cdr_archive MODIFY channel VARCHAR(255) DEFAULT 0");

        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('ringless_cdr_archive', function (Blueprint $table) {
            //
        });
    }
}
