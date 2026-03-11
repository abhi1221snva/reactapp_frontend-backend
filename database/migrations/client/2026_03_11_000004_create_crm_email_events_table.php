<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks email open / click events for CRM outbound emails.
 * Each outgoing email gets a unique tracking_token; opens and clicks
 * are recorded here and surfaced on template analytics.
 */
class CreateCrmEmailEventsTable extends Migration
{
    public function up()
    {
        Schema::create('crm_email_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('lead_id')->index();
            $table->unsignedInteger('template_id')->nullable()->index();
            $table->enum('event_type', ['sent', 'opened', 'clicked', 'bounced', 'unsubscribed'])->default('sent');
            $table->string('tracking_token', 64)->unique();
            $table->text('link_url')->nullable();           // for click events
            $table->string('user_agent', 255)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index('event_type');
            $table->index(['lead_id', 'event_type']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('crm_email_events');
    }
}
