<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCountryWisePackageRateTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('country_wise_package_rate', function (Blueprint $table) {
            $table->id();
            $table->uuid('package_key');
            $table->foreign('package_key')->references('key')->on('packages');
            $table->integer('phone_code');
            $table->decimal('call_rate_per_minute', 5,4)->default(0);
            $table->decimal('rate_six_by_six_sec', 5,4)->default(0);
            $table->decimal('rate_per_sms', 5,2)->default(0);
            $table->decimal('rate_per_did', 5,2)->default(0);
            $table->decimal('rate_per_fax', 5,2)->default(0);
            $table->decimal('rate_per_email', 5,4)->default(0);
            $table->enum('status', array('0','1'))->default('0'); // 0-no,1-yes
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
        Schema::dropIfExists('country_wise_package_rate');
    }
}
