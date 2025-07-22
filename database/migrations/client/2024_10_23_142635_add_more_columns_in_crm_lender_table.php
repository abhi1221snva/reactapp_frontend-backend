<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddMoreColumnsInCrmLenderTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('crm_lender', function (Blueprint $table) {
            $table->string('min_avg_revenue')->nullable(); 
            $table->string('min_monthly_deposit')->nullable(); 
            $table->string('max_mca_payoff_amount')->nullable(); 
            $table->string('loc')->nullable(); 
            $table->string('ownership_percentage')->nullable(); 
            $table->string('factor_rate')->nullable(); 
            $table->string('prohibited_industry')->nullable(); 
            $table->string('restricted_industry_note')->nullable(); 
            $table->string('restricted_state_note')->nullable(); 

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
