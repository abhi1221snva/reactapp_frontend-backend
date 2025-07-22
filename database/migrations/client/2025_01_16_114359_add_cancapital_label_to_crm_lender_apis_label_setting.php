<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddCancapitalLabelToCrmLenderApisLabelSetting extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('crm_lender_apis_label_setting', function (Blueprint $table) {
            $table->string('cancapital_label')->nullable()->after('specialty_label');
            $table->string('rapid_label')->nullable()->after('cancapital_label');
            $table->string('biz2credit_label')->nullable()->after('rapid_label');


            
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
