<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateClientUsageMonthlyTable extends Migration
{
    public function up()
    {
        Schema::connection('master')->create('client_usage_monthly', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('client_id');
            $table->char('year_month', 7);          // "2026-05"
            $table->unsignedInteger('calls_count')->default(0);
            $table->unsignedInteger('sms_count')->default(0);
            $table->unsignedInteger('agents_peak')->default(0);
            $table->timestamps();

            $table->unique(['client_id', 'year_month'], 'uk_client_month');
            $table->index('client_id', 'idx_usage_client');
        });
    }

    public function down()
    {
        Schema::connection('master')->dropIfExists('client_usage_monthly');
    }
}
