<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddColumnToCrmLeadData extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('crm_lead_data', function (Blueprint $table) {
        if (Schema::hasColumn('crm_lead_data', 'dob')) {
            Schema::table('crm_lead_data', function (Blueprint $table) {
                $table->dropColumn('dob');
            });
        }

        if (Schema::hasColumn('crm_lead_data', 'unique_url')) {
            Schema::table('crm_lead_data', function (Blueprint $table) {
                $table->dropColumn('unique_url');
            });
        }


        if (Schema::hasColumn('crm_lead_data', 'unique_token')) {
            Schema::table('crm_lead_data', function (Blueprint $table) {
                $table->dropColumn('unique_token');
            });
        }
        
        Schema::table('crm_lead_data', function (Blueprint $table) {
            $table->string('dob')->nullable();
            $table->string('unique_token',255)->nullable();
            $table->string('unique_url',255)->nullable();

            
        });
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('crm_lead_data', function (Blueprint $table) {
            //
        });
    }
}
