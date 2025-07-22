<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AlterFaxTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasColumn('fax', 'callid'))
        {
            Schema::table('fax', function (Blueprint $table)
            {
                $table->dropColumn('callid');
            });
        }

        Schema::table('fax', function (Blueprint $table) {
            $table->string('ref_id')->nullable();
            $table->unsignedTinyInteger("fax_type")->default(1)->comment('1-recevied, 0-send');
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
            $table->dropColumn(["ref_id"]);
            $table->dropColumn(["fax_type"]);
        });
    }
}
