<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateReportLogs extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('report_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('report_name', 60);
            $table->date("report_date");
            $table->string("sent_to_email");
            $table->json("data");
            $table->string("view_file");
            $table->string("source", 100);
            $table->timestamps();
            $table->index(['report_name','report_date','sent_to_email']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('report_logs');
    }
}
