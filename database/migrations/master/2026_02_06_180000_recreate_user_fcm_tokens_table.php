<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class RecreateUserFcmTokensTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Drop the table if it exists to clear out bad schema/data
        Schema::connection('master')->dropIfExists('user_fcm_tokens');

        // Double check: If it still exists (edge case), force drop with raw SQL
        if (Schema::connection('master')->hasTable('user_fcm_tokens')) {
            DB::connection('master')->statement('DROP TABLE user_fcm_tokens');
        }

        // Create it fresh
        Schema::connection('master')->create('user_fcm_tokens', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id')->index();
            $table->string('device_token');
            $table->enum('device_type', ['web', 'android', 'ios'])->default('web');
            $table->timestamp('last_used_at')->nullable();
            $table->unique(['user_id', 'device_type']);
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::connection('master')->dropIfExists('user_fcm_tokens');
    }
}
