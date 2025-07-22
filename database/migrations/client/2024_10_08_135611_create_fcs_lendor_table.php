<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateFcsLendorTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('fcs_lendor', function (Blueprint $table) {
            $table->id();
            $table->string('bank_name')->nullable(); 
            $table->string('month')->nullable(); 
            $table->string('deposits')->nullable(); 
            $table->string('adjustment')->nullable(); 
            $table->string('revenue')->nullable(); 
            $table->string('adb')->nullable(); 
            $table->string('deposits2')->nullable(); 
            $table->string('nsfs')->nullable(); 
            $table->string('negatives')->nullable(); 
            $table->string('ending_balance')->nullable(); 
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
        Schema::dropIfExists('fcs_lendor');
    }
}
