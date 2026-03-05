<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAuditLogTable extends Migration
{
    public function up()
    {
        Schema::connection('master')->create('audit_log', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('client_id');
            $table->tinyInteger('user_level');
            $table->string('method', 10);
            $table->string('path', 500);
            $table->json('payload')->nullable();
            $table->ipAddress('ip');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at'], 'idx_audit_user_created');
            $table->index(['client_id', 'created_at'], 'idx_audit_client_created');
        });
    }

    public function down()
    {
        Schema::connection('master')->dropIfExists('audit_log');
    }
}
