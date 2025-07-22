<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddSmtpFrom extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        //Schema::table('smtp_setting', function (Blueprint $table) {
        //    $table->dropColumn('sender_type'); // enum column
        //});

        Schema::table('smtp_setting', function (Blueprint $table) {
            $table->string('from_email')->nullable();
            $table->string('from_name')->nullable();
            $table->index('sender_type');
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
            $table->dropIndex(['sender_type']);
            $table->dropColumn(["from_email", "from_name"]);
        });
    }
}
