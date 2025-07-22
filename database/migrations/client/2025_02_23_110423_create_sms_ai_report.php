<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSmsAiReport extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('sms_ai_report', function (Blueprint $table) {
            $table->id();
            $table->json('report_data'); // Stores the whole JSON
            $table->dateTime('time_period_from'); // Stores 'from' timestamp
            $table->dateTime('time_period_to'); // Stores 'to' timestamp
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
        Schema::dropIfExists('sms_ai_report');
    }
}
