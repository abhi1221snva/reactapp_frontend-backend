<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateDidPoolAuditTable extends Migration
{
    public function up()
    {
        Schema::connection('master')->create('did_pool_audit', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('did_pool_id');
            $table->string('phone_number', 20);
            $table->string('action', 30)->comment('assigned, released, blocked, unblocked, imported, cooldown_cleared');
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->unsignedInteger('client_id')->nullable();
            $table->unsignedBigInteger('performed_by')->nullable()->comment('User ID or null for system');
            $table->string('triggered_by', 30)->default('system')->comment('system, admin, scheduler');
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['did_pool_id', 'created_at']);
            $table->index('phone_number');
        });
    }

    public function down()
    {
        Schema::connection('master')->dropIfExists('did_pool_audit');
    }
}
