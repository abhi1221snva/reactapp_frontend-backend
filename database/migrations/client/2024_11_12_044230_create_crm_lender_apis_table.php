<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCrmLenderApisTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('crm_lender_apis', function (Blueprint $table) {
            $table->id();
            $table->string('username');
            $table->string('password');
            $table->string('api_key');
            $table->string('url');
            $table->string('type');
            $table->integer('crm_lender_id');
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
        Schema::dropIfExists('crm_lender_apis');
    }
}
