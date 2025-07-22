<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddAlternatePhoneListHeader extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('list_header', function (Blueprint $table) {
            $table->string('alternate_phone')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasColumn('list_header', 'alternate_phone')) {
            Schema::table('list_header', function (Blueprint $table) {
                $table->dropColumn('alternate_phone');
            });
        }
    }
}
