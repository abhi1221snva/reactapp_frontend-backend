<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddDeliveryStatusInFaxTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('fax', function (Blueprint $table) {
            $table->string('delivery_status', 20)->comment('Delivery Status of fax - taken from third party apis')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('fax', function (Blueprint $table) {
            $table->dropColumn('delivery_status');
        });
    }
}
