<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateLeadSourceWebhookTokensTable extends Migration
{
    public function up()
    {
        Schema::connection('master')->create('lead_source_webhook_tokens', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('client_id');
            $table->unsignedBigInteger('source_id');
            $table->char('token', 64)->unique();
            $table->timestamps();

            $table->index(['client_id', 'source_id']);
        });
    }

    public function down()
    {
        Schema::connection('master')->dropIfExists('lead_source_webhook_tokens');
    }
}
