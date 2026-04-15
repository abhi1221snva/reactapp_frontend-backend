<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pivot table assigning agents (users) to campaigns for the auto-dialer.
 * An agent must be in this table for the dialer to originate calls to them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('campaign_agents')) {
            return;
        }

        Schema::create('campaign_agents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('campaign_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamps();

            $table->unique(['campaign_id', 'user_id']);
            $table->index('campaign_id');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_agents');
    }
};
