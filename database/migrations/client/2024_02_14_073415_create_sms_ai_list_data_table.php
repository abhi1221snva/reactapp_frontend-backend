<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSmsAiListDataTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('sms_ai_list_data', function (Blueprint $table) {
            $table->id();
            $table->integer('list_id');
            $table->string('option_1')->nullable();
            $table->string('option_2')->nullable();
            $table->string('option_3')->nullable();
            $table->string('option_4')->nullable();
            $table->string('option_5')->nullable();
            $table->string('option_6')->nullable();
            $table->string('option_7')->nullable();
            $table->string('option_8')->nullable();
            $table->string('option_9')->nullable();
            $table->string('option_10')->nullable();
            $table->string('option_11')->nullable();
            $table->string('option_12')->nullable();
            $table->string('option_13')->nullable();
            $table->string('option_14')->nullable();
            $table->string('option_15')->nullable();
            $table->string('option_16')->nullable();
            $table->string('option_17')->nullable();
            $table->string('option_18')->nullable();
            $table->string('option_19')->nullable();
            $table->string('option_20')->nullable();
            $table->string('option_21')->nullable();
            $table->string('option_22')->nullable();
            $table->string('option_23')->nullable();
            $table->string('option_24')->nullable();
            $table->string('option_25')->nullable();
            $table->string('option_26')->nullable();
            $table->string('option_27')->nullable();
            $table->string('option_28')->nullable();
            $table->string('option_29')->nullable();
            $table->string('option_30')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('sms_ai_list_data');
    }
}
