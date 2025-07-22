<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddMarkAsCalledColumnToCallback extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('callback', function (Blueprint $table) {
            $table->enum('mark_as_called', array('0','1','2'))->nullable()->comment('0-no, 1-yes, 2-cancel');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasColumn('callback', 'mark_as_called')) {
            Schema::table('callback', function (Blueprint $table) {
                $table->dropColumn('mark_as_called');
            });
        }
    }
}
