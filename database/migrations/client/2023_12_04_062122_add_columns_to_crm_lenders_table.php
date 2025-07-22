<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddColumnsToCrmLendersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('crm_lenders', function (Blueprint $table) {
            //
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('crm_lenders', function (Blueprint $table) {
            $table->string('secondary_email2')->nullable();
            $table->enum('reverse_consolidation', array('1','0'))->default(1)->nullable()->comment('0-no,1-yes');

        });
    }
}
