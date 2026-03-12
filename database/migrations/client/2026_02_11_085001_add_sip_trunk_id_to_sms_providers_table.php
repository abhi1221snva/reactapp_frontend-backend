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
        Schema::table('sms_providers', function (Blueprint $table) {
            if (!Schema::hasColumn('sms_providers', 'twilio_trunk_id')) $table->string('twilio_trunk_id')->nullable();
            if (!Schema::hasColumn('sms_providers', 'twilio_friendly_name')) $table->string('twilio_friendly_name')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sms_providers', function (Blueprint $table) {
            //
        });
    }
};
