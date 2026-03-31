<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_configs', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 50)->unique();
            $table->text('api_key')->nullable();
            $table->text('api_secret')->nullable();
            $table->string('endpoint_url', 500)->nullable();
            $table->json('extra_config')->nullable();
            $table->boolean('is_enabled')->default(false);
            $table->bigInteger('configured_by')->unsigned()->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_configs');
    }
};
