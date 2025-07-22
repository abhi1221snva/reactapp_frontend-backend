<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCrmDocumentsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('crm_documents', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('lead_id');
            $table->string('document_name',50)->nullable();
            $table->string('document_type', 50)->nullable();
            $table->string('file_name',50)->nullable();
            $table->string('file_size', 50)->nullable();
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
        Schema::dropIfExists('crm_documents');
    }
}
