<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddColumnsToCrmLenderTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('crm_lender', function (Blueprint $table) {
            $table->string('lender_type', 50)->nullable();
            $table->string('data_type',50)->nullable();
            $table->integer('min_credit_score')->nullable();
            $table->integer('max_negative_days')->nullable();
            $table->integer('max_advance')->nullable();                        
            $table->string('nsfs',50)->nullable();
            $table->string('min_time_business',50)->nullable();
            $table->integer('min_amount')->nullable();
            $table->integer('min_deposits')->nullable();
            $table->string('max_position',50)->nullable();
            $table->string('max_term',50)->nullable();
            $table->string('white_label',50)->nullable();
            $table->string('consolidation',50)->nullable();
            $table->string('sole_prop',50)->nullable();
            $table->string('home_business',50)->nullable();
            $table->string('non_profit',50)->nullable();
            $table->string('daily',50)->nullable();
            $table->string('coj_req',50)->nullable();
            $table->string('country',50)->nullable();
            $table->string('not_business_type',50)->nullable();



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
