<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;


class AddFoxPartnerLabelToCrmLenderApisLabelSetting extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('crm_lender_apis_label_setting', function (Blueprint $table) {
            $table->string('fox_partner_label')->nullable()->after('bittyadvance_label');
            
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
