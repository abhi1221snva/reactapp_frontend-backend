<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCreatePdfApplicationsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('create_pdf_applications', function (Blueprint $table) {
            $table->id();
            $table->string('pdf_label');
            $table->integer('crm_label_id');
            $table->enum('status', array('1','0'))->default(1)->nullable()->comment('0-inactive,1-active');
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
        Schema::dropIfExists('create_pdf_applications');
    }
}
