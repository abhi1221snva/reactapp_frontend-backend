<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCrmLabelTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('crm_label', function (Blueprint $table) {
            $table->increments('id');
            $table->string('title');
            $table->boolean('is_deleted')->default(0);
            $table->timestamp('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->timestamp('updated_at')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->enum('edit_mode', array('1','0'))->default(0)->nullable()->comment('0-no,1-yes');
            $table->enum('merchant_required', array('1','0'))->default(0)->nullable()->comment('0-no,1-yes');
            $table->enum('view_on_lead', array('1','0'))->default(0)->nullable()->comment('0-no,1-yes');
            $table->string('custom_values',50)->nullable();
            $table->string('label_title_url', 50)->nullable();
            $table->string('data_type',50)->nullable();
            $table->string('values', 50)->nullable();
            $table->enum('required', array('1','0'))->default(1)->nullable()->comment('0-no,1-yes');
            $table->integer('display_order')->default(0);
            $table->string('column_name',50)->nullable();
            $table->enum('status', array('1','0'))->default(1)->nullable()->comment('0-no,1-yes');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('crm_label');
    }
}
