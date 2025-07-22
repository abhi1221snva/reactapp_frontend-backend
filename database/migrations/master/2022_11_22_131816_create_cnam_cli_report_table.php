<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCnamCliReportTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('cnam_cli_report', function (Blueprint $table) {
            $table->id();
            $table->string('parent_id',10);
            $table->string('cli',12);
            $table->string('cnam',50);
            $table->string('created_date',50);
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
        Schema::dropIfExists('cnam_cli_report');
    }
}
