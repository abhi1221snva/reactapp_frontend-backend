<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateApiLogsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('api_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id'); // ID of the client
            $table->unsignedBigInteger('lender_id'); // ID of the lender
            $table->text('request_data'); // The API request data
            $table->text('response_data'); // The API response data
            $table->integer('status_code'); // HTTP status code returned by the API
            $table->string('request_ip'); // IP address of the client making the request
            $table->string('user_agent'); // The User-Agent header value
            $table->string('businessID'); // The businessID header value
            $table->timestamps(); // Timestamps for created_at and updated_at
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('api_logs');
    }
}
