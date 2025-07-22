<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class UpdateCrmLog extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('crm_log', function (Blueprint $table) {
            $table->dropColumn(['type']);
        });

        Schema::table('crm_log', function (Blueprint $table) {
            $table->text("crm_data")->nullable()->change();
            $table->text("url")->nullable()->change();
            $table->unsignedTinyInteger("type")->default(0);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        //
    }
}
