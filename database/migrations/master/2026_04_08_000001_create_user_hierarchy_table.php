<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateUserHierarchyTable extends Migration
{
    public function up(): void
    {
        Schema::connection('master')->create('user_hierarchy', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('manager_id');
            $table->unsignedBigInteger('client_id');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();

            $table->unique(['user_id', 'manager_id', 'client_id'], 'uq_user_manager_client');
            $table->index(['manager_id', 'client_id'], 'idx_manager_client');
            $table->index('client_id', 'idx_client');
        });
    }

    public function down(): void
    {
        Schema::connection('master')->dropIfExists('user_hierarchy');
    }
}
