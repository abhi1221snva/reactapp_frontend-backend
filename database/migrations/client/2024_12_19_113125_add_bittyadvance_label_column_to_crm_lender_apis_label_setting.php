<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;


class AddBittyadvanceLabelColumnToCrmLenderApisLabelSetting extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('crm_lender_apis_label_setting', function (Blueprint $table) {
            $table->string('bittyadvance_label')->nullable()->after('credibly_label');
            
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
