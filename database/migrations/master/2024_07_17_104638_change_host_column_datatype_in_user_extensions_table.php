<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class ChangeHostColumnDatatypeInUserExtensionsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('user_extensions', function (Blueprint $table) {
            DB::statement("ALTER TABLE `user_extensions` CHANGE `host` `host` VARCHAR(100) COLLATE latin1_swedish_ci NOT NULL");
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('user_extensions', function (Blueprint $table) {
            //
        });
    }
}
