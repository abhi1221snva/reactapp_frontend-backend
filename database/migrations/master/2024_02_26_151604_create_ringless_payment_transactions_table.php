<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateRinglessPaymentTransactionsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('ringless_payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id')->comment('Reference to orders.id');
            $table->string('payment_gateway_type')->nullable()->comment('paypal, stripe etc');
            $table->enum('status', array('failed','success'))->default('success');
            $table->string('response')->nullable();
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
        Schema::dropIfExists('ringless_payment_transactions');
    }
}
