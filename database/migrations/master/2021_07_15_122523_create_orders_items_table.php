<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CreateOrdersItemsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('orders_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('description')->nullable()->default(null);
            $table->unsignedBigInteger('order_id')->comment('Reference to orders.id');
            $table->foreign('order_id')->references('id')->on('orders');
            $table->string('package_key');
            $table->unsignedInteger('quantity')->nullable()->default(null);
            $table->unsignedTinyInteger('billed')->nullable()->default(null);
            $table->decimal('amount', 5,2);
            $table->timestamp('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->timestamp('updated_at')->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'));
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('orders_items');
    }
}
