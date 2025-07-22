<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateGoogleLanguageTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('google_language', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('language')->nullable()->default(null);
            $table->string('voice_type')->nullable()->default(null);
            $table->string('language_code')->nullable()->default(null);
            $table->string('voice_name')->nullable()->default(null);
            $table->string('ssml_gender')->nullable()->default(null);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('google_language');
    }
}
