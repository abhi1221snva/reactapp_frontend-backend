<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddIsExpiredToClientPackagesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('client_packages', function (Blueprint $table) {
            $table->unsignedTinyInteger('is_expired')->default(0)->nullable()->comment('0-false ,1-True'); // 0 - false(not expired), 1- True(expired)
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasColumn('client_packages', 'is_expired')) {
            Schema::table('client_packages', function (Blueprint $table)
            {
                $table->dropColumn('is_expired');
            });
        }
    }
}
