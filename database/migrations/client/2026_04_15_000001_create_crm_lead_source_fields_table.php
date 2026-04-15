<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCrmLeadSourceFieldsTable extends Migration
{
    public function up()
    {
        Schema::create('crm_lead_source_fields', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('lead_source_id');
            $table->string('field_name', 100);
            $table->string('field_label', 255);
            $table->enum('field_type', ['text', 'email', 'list'])->default('text');
            $table->tinyInteger('is_required')->default(0);
            $table->text('description')->nullable();
            $table->json('allowed_values')->nullable();
            $table->integer('display_order')->default(0);
            $table->enum('status', ['1', '0'])->default('1')->comment('0-inactive,1-active');
            $table->timestamps();

            $table->unique(['lead_source_id', 'field_name']);
            $table->index('lead_source_id');
        });
    }

    public function down()
    {
        Schema::dropIfExists('crm_lead_source_fields');
    }
}
