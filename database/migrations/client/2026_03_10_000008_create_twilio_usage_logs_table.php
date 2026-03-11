<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTwilioUsageLogsTable extends Migration
{
    public function up(): void
    {
        Schema::create('twilio_usage_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('category', 80);      // e.g. calls, sms, recordings
            $table->string('description')->nullable();
            $table->unsignedBigInteger('count')->default(0);
            $table->decimal('usage', 18, 4)->default(0);
            $table->string('usage_unit', 20)->nullable();
            $table->decimal('price', 18, 6)->default(0);
            $table->string('price_unit', 5)->default('USD');
            $table->date('start_date');
            $table->date('end_date');
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->index(['category', 'start_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('twilio_usage_logs');
    }
}
