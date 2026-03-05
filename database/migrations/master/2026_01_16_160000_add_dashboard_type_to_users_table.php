<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddDashboardTypeToUsersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::connection('master')->table('users', function (Blueprint $table) {
            $table->tinyInteger('dashboard_type')->default(1)->comment('1=Dialer Dashboard, 2=CRM Dashboard');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::connection('master')->table('users', function (Blueprint $table) {
            $table->dropColumn('dashboard_type');
        });
    }
}
