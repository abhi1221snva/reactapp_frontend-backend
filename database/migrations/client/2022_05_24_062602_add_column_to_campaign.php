<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddColumnToCampaign extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('campaign', function (Blueprint $table) {
            $table->string('call_ratio', 4)->default(1);
            $table->string('duration', 3)->default(0);
            
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasColumn('campaign', 'call_ratio'))
        {
            Schema::table('campaign', function (Blueprint $table)
            {
                $table->dropColumn('call_ratio');
            });
        }

        if (Schema::hasColumn('campaign', 'duration'))
        {
            Schema::table('campaign', function (Blueprint $table)
            {
                $table->dropColumn('duration');
            });
        }
    }
}
