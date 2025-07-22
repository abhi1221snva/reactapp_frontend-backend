<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddAutomatedDurationColumnToCampaign extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('campaign', function (Blueprint $table) {
            $table->string('automated_duration', 3)->default(0);
            
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('campaign', function (Blueprint $table) {
            if (Schema::hasColumn('campaign', 'automated_duration'))
            {
                Schema::table('campaign', function (Blueprint $table)
                {
                    $table->dropColumn('automated_duration');
                });
            }
        });
    }
}
