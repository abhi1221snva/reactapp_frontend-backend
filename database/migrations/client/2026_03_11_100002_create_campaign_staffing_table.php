<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('campaign_staffing')) {
            return;
        }
        Schema::create('campaign_staffing', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('campaign_id')->unique();
            $table->unsignedInteger('required_agents')->default(0);
            $table->unsignedInteger('min_agents')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_staffing');
    }
};
