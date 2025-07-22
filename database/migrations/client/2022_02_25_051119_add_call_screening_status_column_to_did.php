<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddCallScreeningStatusColumnToDid extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('did', function (Blueprint $table) {
            $table->enum('call_screening_status', array('1','0'))->default(0)->nullable()->comment('0-inactive,1-active');
            
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasColumn('did', 'call_screening_status'))
        {
            Schema::table('did', function (Blueprint $table)
            {
                $table->dropColumn('call_screening_status');
            });
        }
    }
}
