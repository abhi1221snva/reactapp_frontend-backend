<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddSpecialtyLabelColumnToCrmLenderApisLabelSettingTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('crm_lender_apis_label_setting', function (Blueprint $table) {
            $table->string('specialty_label')->nullable()->after('credibly_label');
            $table->string('forward_financing_label')->nullable()->after('specialty_label');
            
            
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('crm_lender_apis_label_setting', function (Blueprint $table) {
            //
        });
    }
}
