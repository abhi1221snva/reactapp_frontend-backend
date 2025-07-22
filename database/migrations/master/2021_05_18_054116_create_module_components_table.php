<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateModuleComponentsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('module_components', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('name');
            $table->unsignedTinyInteger('is_active')->default(0);
            $table->string('url')->unique()->null();
            $table->string('logo');
            $table->unsignedInteger('min_level');
            $table->unsignedSmallInteger('display_order');
            $table->string('parent_key');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'));
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('module_components');
    }
}
