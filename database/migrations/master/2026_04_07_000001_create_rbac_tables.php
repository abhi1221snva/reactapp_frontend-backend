<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateRbacTables extends Migration
{
    public function up()
    {
        // 1. Route groups — logical groupings of backend URL prefixes
        Schema::connection('master')->create('route_groups', function (Blueprint $table) {
            $table->increments('id');
            $table->string('key', 60)->unique();
            $table->string('name', 100);
            $table->json('url_patterns');
            $table->enum('engine', ['dialer', 'crm', 'shared'])->default('shared');
            $table->string('description', 255)->nullable();
            $table->smallInteger('display_order')->unsigned()->default(0);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->default(DB::raw('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'));
        });

        // 2. Role → route group permissions
        Schema::connection('master')->create('role_route_permissions', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('role_id');
            $table->string('route_group_key', 60);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['role_id', 'route_group_key'], 'uk_role_route');
            $table->index('role_id', 'idx_role');
            $table->index('route_group_key', 'idx_group');
        });

        // 3. Sidebar menu items — drives React sidebar dynamically
        Schema::connection('master')->create('sidebar_menu_items', function (Blueprint $table) {
            $table->increments('id');
            $table->enum('engine', ['dialer', 'crm']);
            $table->string('section_label', 50);
            $table->string('route_path', 150);
            $table->string('label', 80);
            $table->string('icon_name', 60);
            $table->string('route_group_key', 60)->nullable();
            $table->tinyInteger('min_level')->unsigned()->default(1);
            $table->smallInteger('display_order')->unsigned()->default(0);
            $table->boolean('is_active')->default(true);
            $table->string('badge_source', 50)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->default(DB::raw('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'));

            $table->index(['engine', 'is_active', 'display_order'], 'idx_engine_active');
        });
    }

    public function down()
    {
        Schema::connection('master')->dropIfExists('sidebar_menu_items');
        Schema::connection('master')->dropIfExists('role_route_permissions');
        Schema::connection('master')->dropIfExists('route_groups');
    }
}
