<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddOohFieldsInDidTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('did', function (Blueprint $table) {
            $table->integer('call_time_department_id')->nullable();
            $table->integer('call_time_holiday')->nullable();
            $table->string('dest_type_ooh', 10)->nullable();
            $table->string('ivr_id_ooh')->nullable();
            $table->string('extension_ooh', 50)->nullable();
            $table->string('voicemail_id_ooh', 25)->nullable();
            $table->string('forward_number_ooh')->nullable();
            $table->string('conf_id_ooh')->nullable();
            $table->string('queue_id_ooh')->nullable();
            $table->string('ingroup_ooh', 3)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('did', function (Blueprint $table) {
            $table->dropColumn('call_time_department_id');
            $table->dropColumn('call_time_holiday');
            $table->dropColumn('dest_type_ooh');
            $table->dropColumn('ivr_id_ooh');
            $table->dropColumn('extension_ooh');
            $table->dropColumn('voicemail_id_ooh');
            $table->dropColumn('forward_number_ooh');
            $table->dropColumn('conf_id_ooh');
            $table->dropColumn('queue_id_ooh');
            $table->dropColumn('ingroup_ooh');
        });
    }
}
