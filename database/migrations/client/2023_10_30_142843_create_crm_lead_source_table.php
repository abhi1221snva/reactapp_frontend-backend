<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCrmLeadSourceTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('crm_lead_source', function (Blueprint $table) {
            $table->id();
            $table->string('url')->nullable();
            $table->string('source_title', 50);
            $table->enum('status', array('1','0'))->default(1)->nullable()->comment('0-inactive,1-active');
            $table->integer('unique_id');
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
        Schema::dropIfExists('crm_lead_source');
    }
}
