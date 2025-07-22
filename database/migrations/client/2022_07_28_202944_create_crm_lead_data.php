<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCrmLeadData extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('crm_lead_data', function (Blueprint $table) {
            $table->id();
            $table->string('first_name',50);
            $table->string('last_name',50);
            $table->string('email',50);
            $table->string('phone',50);
            $table->enum('gender', array('male','female','other','none'))->default('none')->nullable();
            $table->date('dob')->nullable();
            $table->string('city',50)->nullable();
            $table->string('state',50)->nullable();
            $table->string('country',50)->nullable();
            $table->string('address',50)->nullable();
            $table->string('legal_company_name',100)->nullable();
            $table->string('lead_type',20)->nullable();
            $table->string('lead_status', 50);
            $table->integer('assigned_to')->nullable();
            $table->integer('lead_source_id')->nullable();;
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
        Schema::dropIfExists('crm_lead_data');
    }
};
