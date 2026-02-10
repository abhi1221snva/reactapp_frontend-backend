<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddPusherUuidToUsersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::connection('master')->hasTable('users')) {
            Schema::connection('master')->table('users', function (Blueprint $table) {
                if (!Schema::connection('master')->hasColumn('users', 'pusher_uuid')) {
                    $table->string('pusher_uuid', 36)->nullable()->after('email')->index();
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::connection('master')->hasTable('users')) {
            Schema::connection('master')->table('users', function (Blueprint $table) {
                if (Schema::connection('master')->hasColumn('users', 'pusher_uuid')) {
                    $table->dropColumn('pusher_uuid');
                }
            });
        }
    }
}
