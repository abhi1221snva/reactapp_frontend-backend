<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('lead_change_logs')) {
            return;
        }

        Schema::create('lead_change_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('lead_id');
            $table->char('batch_id', 36);
            $table->string('source', 30)->default('crm_ui');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('user_type', 20)->default('agent');
            $table->json('changes');
            $table->string('ip_address', 45)->nullable();
            $table->string('summary', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('lead_id');
            $table->index('batch_id');
            $table->index(['lead_id', 'source']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_change_logs');
    }
};
