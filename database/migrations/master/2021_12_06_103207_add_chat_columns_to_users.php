<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddChatColumnsToUsers extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'avatar')) {
                $table->string('avatar')->default('default_avatar.png');
            }
            if (!Schema::hasColumn('users', 'messenger_color')) {
                $table->string('messenger_color')->default('#2180f3');
            }
            if (!Schema::hasColumn('users', 'active_status')) {
                $table->boolean('active_status')->default(0);
            }
            if (!Schema::hasColumn('users', 'dark_mode')) {
                $table->boolean('dark_mode')->default(0);
            }
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('avatar');
            $table->dropColumn('messenger_color');
            $table->dropColumn('active_status');
            $table->dropColumn('dark_mode');
        });
    }
}
