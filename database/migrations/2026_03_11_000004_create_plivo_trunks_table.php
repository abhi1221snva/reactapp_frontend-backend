<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePlivoTrunksTable extends Migration
{
    public function up(): void
    {
        Schema::create('plivo_trunks', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('app_id', 64)->unique();         // Plivo Application ID (trunk uses apps)
            $table->string('app_name');
            $table->string('answer_url')->nullable();
            $table->string('hangup_url')->nullable();
            $table->string('status_url')->nullable();
            $table->enum('answer_method', ['GET', 'POST'])->default('POST');
            $table->enum('status', ['active', 'deleted'])->default('active');
            $table->json('ip_acl')->nullable();              // Allowed IP ranges / SIP IPs
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plivo_trunks');
    }
}
