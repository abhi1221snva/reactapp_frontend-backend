<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AsteriskServerAddPort extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('asterisk_server', function (Blueprint $table) {
            $table->unsignedInteger('ssh_port')->default(10347);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasColumn('asterisk_server', 'ssh_port')) {
            Schema::table('asterisk_server', function (Blueprint $table)
            {
                $table->dropColumn('ssh_port');
            });
        }
    }
}
