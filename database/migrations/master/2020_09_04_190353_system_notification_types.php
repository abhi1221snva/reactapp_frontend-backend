<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;


class SystemNotificationTypes extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */

    public function up()
    {
        Schema::dropIfExists('system_notification_types');
        Schema::create('system_notification_types', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->enum('type', ['email', 'sms']);
            $table->smallInteger('display_order')->default(0);
            $table->timestamp('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->timestamp('updated_at')->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'));
        });
        DB::raw("UPDATE migrations SET migration='2020_09_04_190353_system_notification_types' WHERE migration='2020_08_28_190353_system_notification_types'");
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
