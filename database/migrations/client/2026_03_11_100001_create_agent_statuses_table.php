<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('agent_statuses')) {
            return;
        }
        Schema::create('agent_statuses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->enum('status', ['available', 'on_call', 'on_break', 'after_call_work', 'offline'])->default('offline');
            $table->unsignedBigInteger('campaign_id')->nullable();
            $table->timestamp('last_updated_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('campaign_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_statuses');
    }
};
