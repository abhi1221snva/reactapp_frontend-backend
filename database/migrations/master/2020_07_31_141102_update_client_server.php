<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class UpdateClientServer extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('client_server', function (Blueprint $table) {
            $table->unsignedInteger("id")->primary()->change();
            $table->unsignedInteger("client_id")->change();
            $table->unsignedInteger("server_id")->default(1);
        });

        foreach (\App\Model\Master\ClientServers::all() as $row) {
            $row->server_id = intval($row->ip_address);
            $row->save();
        }

        Schema::table('client_server', function (Blueprint $table) {
            $table->foreign('client_id')->references('id')->on('clients');
            $table->foreign('server_id')->references('id')->on('asterisk_server');
        });

        Schema::table('client_server', function (Blueprint $table) {
            $table->increments("id")->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('client_server', function (Blueprint $table) {
            $table->dropColumn(["server_id"]);
        });
    }
}
