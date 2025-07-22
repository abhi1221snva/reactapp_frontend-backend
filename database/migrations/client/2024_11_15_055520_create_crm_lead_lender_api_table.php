<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCrmLeadLenderApiTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('crm_lead_lender_api', function (Blueprint $table) {
            $table->id();  // Primary key
            $table->unsignedBigInteger('lead_id');  // Foreign key to leads table
            $table->unsignedBigInteger('lender_id');  // Foreign key to leads table
            $table->unsignedBigInteger('client_id');  // Foreign key to leads table
            $table->string('lender_api_type');  // Type of lender API
            $table->string('businessID')->nullable();  // Nullable field for businessID
            $table->timestamps();

            // Indexes and Foreign Key Constraints
            $table->foreign('lead_id')->references('id')->on('crm_lead_data')->onDelete('cascade');  // References lead table
            $table->index('lender_api_type');  // Index for lender API type for faster queries
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('crm_lead_lender_api');
    }
}
