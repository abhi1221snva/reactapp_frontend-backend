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
        Schema::create('call_timers', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable(); // e.g. "Default Weekly Timer"
            $table->text('description')->nullable(); // e.g. "Default Weekly Timer"
            $table->json('week_plan')->nullable(); // store weekly plan as JSON
            $table->timestamps();

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('call_timers');
    }
};
