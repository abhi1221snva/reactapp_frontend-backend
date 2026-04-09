<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateDripV2StepsTable extends Migration
{
    public function up(): void
    {
        Schema::create('drip_v2_steps', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('campaign_id');
            $table->unsignedInteger('position');
            $table->enum('channel', ['email', 'sms'])->default('email');
            $table->unsignedInteger('delay_value')->default(0);
            $table->enum('delay_unit', ['minutes', 'hours', 'days'])->default('hours');
            $table->time('send_at_time')->nullable();
            $table->string('subject', 255)->nullable();
            $table->text('body_html')->nullable();
            $table->text('body_plain')->nullable();
            $table->unsignedBigInteger('email_template_id')->nullable();
            $table->unsignedBigInteger('sms_template_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['campaign_id', 'position']);
            $table->foreign('campaign_id')->references('id')->on('drip_v2_campaigns')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drip_v2_steps');
    }
}
