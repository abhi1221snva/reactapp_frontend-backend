<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCrmScheduledTaskTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('crm_scheduled_task', function (Blueprint $table) {
            $table->id();
            $table->integer('lead_id');
            $table->string('task_name')->nullable();
            $table->date('date');
            $table->time('time');
            $table->string('notes')->nullable();
            $table->integer('user_id');
            $table->boolean('is_sent')->default(false);
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
        Schema::dropIfExists('crm_scheduled_task');
    }
}
