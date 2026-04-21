<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAuthEventsTable extends Migration
{
    public function up()
    {
        Schema::connection('master')->create('auth_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('event_type', 50)->index();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'event_type', 'created_at']);
        });
    }

    public function down()
    {
        Schema::connection('master')->dropIfExists('auth_events');
    }
}
