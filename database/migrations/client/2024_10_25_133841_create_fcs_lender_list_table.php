<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateFcsLenderListTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('fcs_lender_list', function (Blueprint $table) {
            $table->id();
            $table->integer('lead_id')->nullable(); 
            $table->integer('bank_id')->nullable(); 
            $table->string('lender_name')->nullable(); 
            $table->string('funding_date')->nullable(); 
            $table->string('net')->nullable(); 
            $table->string('funding')->nullable(); 
            $table->string('funding_factor')->nullable(); 
            $table->string('weekly')->nullable(); 
            $table->string('daily')->nullable(); 
            $table->string('balance')->nullable(); 
            $table->string('days')->nullable(); 
            $table->string('withhold')->nullable(); 
            $table->string('end_date')->nullable(); 
            $table->string('transfer_accounts')->nullable();
            $table->string('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('fcs_lender_list');
    }
}
