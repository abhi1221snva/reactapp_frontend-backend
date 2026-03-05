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
        if (Schema::hasTable('attendance_breaks')) {
            return;
        }
        Schema::create('attendance_breaks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('attendance_id');
            $table->timestamp('break_start_at');
            $table->timestamp('break_end_at')->nullable();
            $table->integer('duration_minutes')->nullable();
            $table->enum('break_type', ['lunch', 'short', 'personal', 'other'])->default('short');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('attendance_id');
            $table->foreign('attendance_id')->references('id')->on('attendances')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_breaks');
    }
};
