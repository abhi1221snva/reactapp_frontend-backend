<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateRinglessOrdersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('ringless_orders', function (Blueprint $table) {
            $table->id();
            $table->integer('client_id')->nullable();
            $table->decimal('net_amount', 5,2);
            $table->enum('discount_type', array('fixed','percentage'))->nullable();
            $table->decimal('discount_price', 5,2)->nullable();
            $table->decimal('gross_amount', 5,2)->comment('gross amount considering discount deductions');
            $table->enum('status', array('initiated','success','failed'));
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
        Schema::dropIfExists('ringless_orders');
    }
}
