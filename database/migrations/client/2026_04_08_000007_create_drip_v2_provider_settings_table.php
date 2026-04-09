<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateDripV2ProviderSettingsTable extends Migration
{
    public function up(): void
    {
        Schema::create('drip_v2_provider_settings', function (Blueprint $table) {
            $table->id();
            $table->enum('provider', ['sendgrid', 'twilio', 'smtp'])->default('smtp');
            $table->json('config')->nullable();
            $table->boolean('is_default')->default(false);
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drip_v2_provider_settings');
    }
}
