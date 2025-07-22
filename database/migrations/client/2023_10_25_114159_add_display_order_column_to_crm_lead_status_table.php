<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddDisplayOrderColumnToCrmLeadStatusTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('crm_lead_status', function (Blueprint $table) {
            $table->integer('display_order');
            $table->enum('view_on_dashboard', array('1','0'))->default(0)->nullable()->comment('0-no,1-yes');

        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('crm_lead_status', function (Blueprint $table) {
            //
        });
    }
}
