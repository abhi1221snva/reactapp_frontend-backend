<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateProspectPackages extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('prospect_packages', function (Blueprint $table) {
            $table->unsignedBigInteger('prospect_id');
            $table->uuid('package_key');
            $table->unsignedInteger('quantity');
            $table->dateTime('start_time');
            $table->dateTime('end_time')->nullable();
            $table->dateTime('expiry_time')->nullable();
            $table->tinyInteger('billed');          #1-monthly, 2-annually
            $table->unsignedBigInteger('payment_cent_amount')->nullable();
            $table->dateTime('payment_time')->nullable();
            $table->string('payment_method')->nullable();
            $table->string('psp_reference', 100)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'));
            $table->primary(['prospect_id','package_key']);
            $table->foreign('prospect_id')->references('id')->on('prospects');
            $table->foreign('package_key')->references('key')->on('packages');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('prospect_packages');
    }
}
