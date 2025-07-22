<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddRedirectLastAgentColumnToDid extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('did', function (Blueprint $table) {
            $table->enum('redirect_last_agent', array('0','1'))->default('0');

        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasColumn('did', 'redirect_last_agent'))
        {
            Schema::table('did', function (Blueprint $table)
            {
                $table->dropColumn('redirect_last_agent');
            });
        }
    }
}
