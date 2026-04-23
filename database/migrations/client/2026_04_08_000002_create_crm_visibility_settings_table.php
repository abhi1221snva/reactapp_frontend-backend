<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class CreateCrmVisibilitySettingsTable extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('crm_visibility_settings')) {
            return;
        }

        Schema::create('crm_visibility_settings', function (Blueprint $table) {
            $table->increments('id');
            $table->tinyInteger('enable_team_visibility')->default(0);
            $table->tinyInteger('enable_hierarchy_visibility')->default(0);
            $table->tinyInteger('enable_creator_visibility')->default(1);
            $table->tinyInteger('enable_multi_assignee_visibility')->default(1);
            $table->tinyInteger('non_admin_min_level')->unsigned()->default(7);
            $table->timestamp('updated_at')->useCurrent();
            $table->unsignedBigInteger('updated_by')->nullable();
        });

        // Seed default row
        DB::table('crm_visibility_settings')->insert([
            'enable_team_visibility'           => 0,
            'enable_hierarchy_visibility'      => 0,
            'enable_creator_visibility'        => 1,
            'enable_multi_assignee_visibility' => 1,
            'non_admin_min_level'              => 7,
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_visibility_settings');
    }
}
