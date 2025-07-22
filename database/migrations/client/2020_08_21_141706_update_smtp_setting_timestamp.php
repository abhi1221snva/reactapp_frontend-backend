<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class UpdateSmtpSettingTimestamp extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('smtp_setting', function (Blueprint $table) {
            $table->dropColumn('status'); // enum column
        });

        Schema::table('smtp_setting', function (Blueprint $table) {
            $table->string('api_key')->nullable()->change();
            $table->unsignedTinyInteger("status")->default(1)->comment('1-active, 0-inactive');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('smtp_setting', function (Blueprint $table) {
            $table->dropColumn(["api_key"]);
        });
    }
}
