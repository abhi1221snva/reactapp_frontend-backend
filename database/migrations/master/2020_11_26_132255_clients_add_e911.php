<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class ClientsAddE911 extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->unsignedTinyInteger('e911')->default(0);
            $table->string('e911_did')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasColumn('clients', 'e911')) {
            Schema::table('clients', function (Blueprint $table)
            {
                $table->dropColumn('e911');
            });
        }

        if (Schema::hasColumn('clients', 'e911_did')) {
            Schema::table('clients', function (Blueprint $table)
            {
                $table->dropColumn('e911_did');
            });
        }
    }
}
