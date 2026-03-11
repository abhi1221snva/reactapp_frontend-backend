<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks duplicate lead detections for audit and review.
 */
class CreateLeadDedupLogTable extends Migration
{
    public function up()
    {
        Schema::create('lead_dedup_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('original_lead_id')->index();
            $table->unsignedBigInteger('duplicate_lead_id')->index();
            $table->string('match_field', 50)->default('phone'); // phone | email | name_phone
            $table->string('match_value', 255)->index();
            $table->enum('action', ['blocked', 'merged', 'flagged'])->default('flagged');
            $table->unsignedInteger('detected_by')->nullable();
            $table->timestamps();

            $table->index(['original_lead_id', 'duplicate_lead_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('lead_dedup_log');
    }
}
