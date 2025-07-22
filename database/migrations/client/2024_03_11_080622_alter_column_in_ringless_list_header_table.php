<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AlterColumnInRinglessListHeaderTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('ringless_list_header', function (Blueprint $table) {
            \DB::statement("ALTER TABLE ringless_list_header CHANGE is_dialing is_dialling VARCHAR(255)");

        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('ringless_list_header', function (Blueprint $table) {
            //
        });
    }
}
