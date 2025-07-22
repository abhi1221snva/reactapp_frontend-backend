<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCampaignTypeTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('campaign_type', function (Blueprint $table) {
            $table->id();
            $table->string('title', 100);
            $table->string('title_url', 100);
            $table->enum('status', array('0','1'))->default('1'); // 0-inactive,1-active
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
        Schema::dropIfExists('campaign_type');
    }
}
