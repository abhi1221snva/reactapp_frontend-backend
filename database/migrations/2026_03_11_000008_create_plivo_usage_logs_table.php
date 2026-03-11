<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePlivoUsageLogsTable extends Migration
{
    public function up(): void
    {
        Schema::create('plivo_usage_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('resource', 80);      // calls, messages, recordings
            $table->string('description')->nullable();
            $table->unsignedBigInteger('total_count')->default(0);
            $table->decimal('total_amount', 18, 6)->default(0);
            $table->decimal('total_duration', 18, 4)->default(0);
            $table->string('duration_unit', 20)->nullable();  // min, sec
            $table->date('date_from');
            $table->date('date_till');
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->index(['resource', 'date_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plivo_usage_logs');
    }
}
