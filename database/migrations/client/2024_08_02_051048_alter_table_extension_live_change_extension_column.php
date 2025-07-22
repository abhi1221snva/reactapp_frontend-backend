<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AlterTableExtensionLiveChangeExtensionColumn extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('extension_live', function (Blueprint $table) {
            DB::statement("ALTER TABLE `extension_live` CHANGE `extension` `extension` VARCHAR(25) COLLATE latin1_swedish_ci NOT NULL");
            
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('extension_live', function (Blueprint $table) {
            //
        });
    }
}
