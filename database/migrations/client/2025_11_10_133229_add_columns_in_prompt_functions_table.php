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
            if (!Schema::hasColumn('prompt_functions', 'curl_request')) $table->longText('curl_request')->nullable();
            if (!Schema::hasColumn('prompt_functions', 'curl_response')) $table->longText('curl_response')->nullable();
            if (!Schema::hasColumn('prompt_functions', 'api_method')) $table->string('api_method', 20)->nullable();
            if (!Schema::hasColumn('prompt_functions', 'api_url')) $table->text('api_url')->nullable();
            if (!Schema::hasColumn('prompt_functions', 'api_body')) $table->longText('api_body')->nullable();
            if (!Schema::hasColumn('prompt_functions', 'api_response')) $table->longText('api_response')->nullable();
            if (!Schema::hasColumn('prompt_functions', 'content')) $table->longText('content')->nullable();
            if (!Schema::hasColumn('prompt_functions', 'description')) $table->text('description')->nullable();
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
