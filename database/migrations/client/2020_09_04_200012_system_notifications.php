<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class SystemNotifications extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::dropIfExists('system_notifications');
        Schema::create('system_notifications', function (Blueprint $table) {
            $table->string('notification_id')->primary();
            $table->unsignedTinyInteger("active")->default(0);
            $table->json("subscribers");
            $table->dateTime('last_attempt')->nullable()->default(null);
            $table->dateTime('last_sent')->nullable()->default(null);
        });
        DB::raw("UPDATE migrations SET migration='2020_09_04_200012_system_notifications' WHERE migration='2020_08_28_200012_system_notifications'");
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('system_notification_types');
    }
}
