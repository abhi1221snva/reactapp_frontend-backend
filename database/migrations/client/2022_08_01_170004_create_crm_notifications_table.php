<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Query\Expression;


class CreateCrmNotificationsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('crm_notifications', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_id')->unsigned()->comment('Reference to master.users.id');
            $table->foreign('user_id')->references('id')->on(new Expression('master.users'));
            $table->bigInteger('lead_id')->unsigned()->comment('Reference to crm_lead_data.id');
            $table->foreign('lead_id')->references('id')->on('crm_lead_data');
            $table->text('message');
            $table->enum('type', array('1','0'))->default(1)->nullable()->comment('0-updates,1-notes');
            $table->enum('status', array('1','0'))->default(1)->nullable()->comment('0-inactive,1-active');
            $table->softDeletes();
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
        Schema::dropIfExists('crm_notifications');
    }
};
