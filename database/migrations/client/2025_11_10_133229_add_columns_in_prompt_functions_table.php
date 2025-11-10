<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('prompt_functions', function (Blueprint $table) {
            $table->longText('curl_request')->nullable();
            $table->longText('curl_response')->nullable();
            $table->string('api_method', 20)->nullable();
            $table->text('api_url')->nullable();
            $table->longText('api_body')->nullable();
            $table->longText('api_response')->nullable();
            $table->longText('content')->nullable();
            $table->text('description')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('prompt_functions', function (Blueprint $table) {
            //
        });
    }
};
