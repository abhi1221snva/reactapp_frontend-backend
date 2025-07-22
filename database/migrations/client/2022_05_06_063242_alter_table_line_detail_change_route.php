<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AlterTableLineDetailChangeRoute extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('line_detail', function (Blueprint $table) {
            \DB::statement("ALTER TABLE `line_detail` CHANGE `route` `route` ENUM('IN','OUT','C2C') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL;");
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('line_detail', function (Blueprint $table) {
            //
        });
    }
}
