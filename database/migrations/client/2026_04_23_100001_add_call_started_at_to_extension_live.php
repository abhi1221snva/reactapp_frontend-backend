<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddCallStartedAtToExtensionLive extends Migration
{
    public function up()
    {
        Schema::table('extension_live', function (Blueprint $table) {
            $table->timestamp('call_started_at')->nullable();
        });
    }

    public function down()
    {
        Schema::table('extension_live', function (Blueprint $table) {
            $table->dropColumn('call_started_at');
        });
    }
}
