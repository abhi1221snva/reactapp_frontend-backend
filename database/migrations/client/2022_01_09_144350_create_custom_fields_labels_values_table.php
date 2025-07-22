<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCustomFieldsLabelsValuesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('custom_fields_labels_values', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('custom_id')->length(11);
            $table->integer('user_id')->length(11);
            $table->string('title_match');
            $table->string('title_links');
            $table->boolean('is_deleted')->default(0);
            $table->timestamp('updated_at')->default(DB::raw('CURRENT_TIMESTAMP'));
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('custom_fields_labels_values');
    }
}
