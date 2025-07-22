<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

 class CreateCrmLenderTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('crm_lender', function (Blueprint $table) {
            $table->id();
            $table->string('lender_name', 255);
            $table->string('email', 255);
            $table->string('phone', 255)->nullable();
            $table->string('contact_person', 255);
            $table->enum('status', array('1','0'))->default('1');
            $table->string('address', 255)->nullable();;
            $table->string('city', 255)->nullable();;
            $table->string('state', 255)->nullable();;
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
        Schema::dropIfExists('crm_lender');
    }
};
