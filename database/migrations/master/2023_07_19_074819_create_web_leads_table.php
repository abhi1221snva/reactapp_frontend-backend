<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateWebLeadsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('web_leads', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('first_name');
            $table->string('last_name')->nullable();
            $table->string('country_code', 4);
            $table->string('mobile', 20);
            $table->string('email')->unique();
            //$table->string('password');
            //$table->string('company_name')->unique();
            //$table->string('address_1')->nullable();
            //$table->string('address_2')->nullable();
            $table->unsignedTinyInteger('status')->default(1);
            $table->uuid('mobile_otp');
            $table->uuid('email_otp');
            //$table->unsignedInteger('client_id_assigned')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'));
            //$table->foreign('client_id_assigned')->references('id')->on('clients');
            //$table->foreign('mobile_otp')->references('id')->on('phone_verifications');
            //$table->foreign('email_otp')->references('id')->on('email_verifications');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('web_leads');
    }
}
