<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddIndustryColumnInCrmLenderTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('crm_lender', function (Blueprint $table) {
            $table->string('industry')->nullable(); 
            $table->string('guideline_state')->nullable(); 
            $table->string('guideline_file')->nullable(); 

        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('crm_lender', function (Blueprint $table) {
            //
        });
    }
}
