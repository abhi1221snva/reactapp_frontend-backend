<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class AddDeviceTokenToUserFcmTokens extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::connection('master')->hasTable('user_fcm_tokens')) {
            Schema::connection('master')->table('user_fcm_tokens', function (Blueprint $table) {
                if (!Schema::connection('master')->hasColumn('user_fcm_tokens', 'device_token')) {
                    // Truncate the table because existing rows are missing the required logical 'device_token'
                    // and adding a unique string column will cause duplicate entry '' errors.
                    DB::connection('master')->table('user_fcm_tokens')->truncate();
                    $table->string('device_token')->unique()->after('user_id');
                }
                if (!Schema::connection('master')->hasColumn('user_fcm_tokens', 'device_type')) {
                    $table->enum('device_type', ['web', 'android', 'ios'])->default('web')->after('device_token');
                }
                if (!Schema::connection('master')->hasColumn('user_fcm_tokens', 'last_used_at')) {
                    $table->timestamp('last_used_at')->nullable()->after('device_type');
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
        Schema::connection('master')->table('user_fcm_tokens', function (Blueprint $table) {
            if (Schema::connection('master')->hasColumn('user_fcm_tokens', 'device_token')) {
                 $table->dropColumn('device_token');
            }
             if (Schema::connection('master')->hasColumn('user_fcm_tokens', 'device_type')) {
                 $table->dropColumn('device_type');
            }
             if (Schema::connection('master')->hasColumn('user_fcm_tokens', 'last_used_at')) {
                 $table->dropColumn('last_used_at');
            }
        });
    }
}
