<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AlterTableCdrArchiveChangeType extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('cdr_archive', function (Blueprint $table) {
            \DB::statement("ALTER TABLE `cdr_archive` CHANGE `type` `type` ENUM('manual','dialer','predictive_dial','outbound_ai') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'dialer';");
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('cdr_archive', function (Blueprint $table) {
            //
        });
    }
}
