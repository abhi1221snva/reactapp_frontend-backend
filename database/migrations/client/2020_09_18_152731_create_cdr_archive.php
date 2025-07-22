<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCdrArchive extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('cdr', function (Blueprint $table) {
            $table->index('start_time');
            $table->index('extension');
            $table->index('campaign_id');
        });
        DB::statement("CREATE TABLE IF NOT EXISTS cdr_archive LIKE cdr;");
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('cdr', function (Blueprint $table)
        {
            $table->dropIndex(['start_time']);
            $table->dropIndex(['extension']);
            $table->dropIndex(['campaign_id']);
        });
        Schema::dropIfExists('cdr_archive');
    }
}
