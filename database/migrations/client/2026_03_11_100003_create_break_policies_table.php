<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('break_policies')) {
            return;
        }
        Schema::create('break_policies', function (Blueprint $table) {
            $table->id();
            // NULL = global default policy; set = campaign-specific
            $table->unsignedBigInteger('campaign_id')->nullable()->unique();
            $table->unsignedInteger('max_concurrent_breaks')->default(3);
            $table->unsignedInteger('max_break_minutes')->default(60);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('break_policies');
    }
};
