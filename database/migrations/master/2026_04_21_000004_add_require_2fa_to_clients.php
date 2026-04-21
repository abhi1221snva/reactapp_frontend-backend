<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddRequire2faToClients extends Migration
{
    public function up()
    {
        Schema::connection('master')->table('clients', function (Blueprint $table) {
            $table->boolean('require_2fa')->default(false)->after('enable_2fa');
        });
    }

    public function down()
    {
        Schema::connection('master')->table('clients', function (Blueprint $table) {
            $table->dropColumn('require_2fa');
        });
    }
}
