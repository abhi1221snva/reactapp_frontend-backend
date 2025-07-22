<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSmsAiTemplatesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('sms_ai_templates', function (Blueprint $table) {
            $table->id();
            $table->string('template_name');
            $table->text('introduction');
            $table->text('description');
            $table->enum('status', array('1','0'))->default('1')->comment('1-yes,0-no');
            $table->enum('is_deleted', array('1','0'))->default('0')->comment('1-yes,0-no');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('sms_ai_templates');
    }
}
