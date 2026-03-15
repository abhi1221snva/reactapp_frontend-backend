<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCrmAutomationsTable extends Migration
{
    public function up()
    {
        Schema::create('crm_automations', function (Blueprint $table) {
            $table->id();
            $table->string('name', 200);
            $table->string('description', 500)->nullable();
            $table->enum('trigger_type', ['status_change','field_update','time_elapsed','document_uploaded','deal_funded','stip_uploaded','offer_received']);
            $table->json('trigger_config')->nullable();
            $table->json('conditions')->nullable();
            $table->json('actions');
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('crm_automations');
    }
}
