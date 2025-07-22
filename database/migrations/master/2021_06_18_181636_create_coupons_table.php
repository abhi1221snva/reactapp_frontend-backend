<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCouponsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('coupons', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name')->nullable()->default(null);
            $table->string('code')->nullable()->default(null);
            $table->enum('type', array('percentage','amount'))->default('amount');
            $table->float('amount', 10, 0)->nullable();
            $table->string('currency_code')->nullable()->default(null);
            $table->timestamp('start_at')->default(null);
            $table->timestamp('expire_at')->default(null);
            $table->enum('status', array('Active','Inactive'))->default('Active');
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
        Schema::dropIfExists('coupons');
    }
}
