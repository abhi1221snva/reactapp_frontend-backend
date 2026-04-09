<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateDripV2CampaignsTable extends Migration
{
    public function up(): void
    {
        Schema::create('drip_v2_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->enum('status', ['draft', 'active', 'paused', 'archived'])->default('draft');
            $table->enum('channel', ['email', 'sms', 'both'])->default('email');
            $table->unsignedBigInteger('email_setting_id')->nullable();
            $table->string('sms_from_number', 20)->nullable();
            $table->json('entry_conditions')->nullable();
            $table->json('exit_conditions')->nullable();
            $table->json('trigger_rules')->nullable();
            $table->time('quiet_hours_start')->nullable();
            $table->time('quiet_hours_end')->nullable();
            $table->string('quiet_hours_tz', 50)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('created_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drip_v2_campaigns');
    }
}
