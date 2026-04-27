<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddTargetSystemToRecycleRule extends Migration
{
    public function up(): void
    {
        Schema::table('recycle_rule', function (Blueprint $table) {
            $table->enum('target_system', ['legacy', 'campaign_queue'])
                  ->default('legacy')
                  ->after('call_time');
            $table->unsignedTinyInteger('max_attempts')
                  ->default(3)
                  ->after('target_system');
        });
    }

    public function down(): void
    {
        Schema::table('recycle_rule', function (Blueprint $table) {
            $table->dropColumn(['target_system', 'max_attempts']);
        });
    }
}
