<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCrmDocumentsTypesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('crm_documents_types', function (Blueprint $table) {
            $table->increments('id');
            $table->string('title', 50);
            $table->string('type_title_url', 50);
            $table->string('values');
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
        Schema::dropIfExists('crm_documents_types');
    }
}
