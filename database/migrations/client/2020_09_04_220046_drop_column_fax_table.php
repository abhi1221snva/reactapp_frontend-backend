<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class DropColumnFaxTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasColumn('fax', 'caller'))
        {
            Schema::table('fax', function (Blueprint $table)
            {
                $table->dropColumn('caller');
            });
        }
        if (Schema::hasColumn('fax', 'dnis'))
        {
            Schema::table('fax', function (Blueprint $table)
            {
                $table->dropColumn('dnis');
            });
        }
        if (Schema::hasColumn('fax', 'file'))
        {
            Schema::table('fax', function (Blueprint $table)
            {
                $table->dropColumn('file');
            });
        }
        if (Schema::hasColumn('fax', 'start_time'))
        {
            Schema::table('fax', function (Blueprint $table)
            {
                $table->dropColumn('start_time');
            });
        }

        Schema::table('fax', function (Blueprint $table) {
            $table->timestamp('start_time')->default(DB::raw('CURRENT_TIMESTAMP'));
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('fax', function (Blueprint $table) {
            //
			$table->text("caller")->nullable();
			$table->text("dnis")->nullable();
			$table->text("file")->nullable();
        });
    }
}
