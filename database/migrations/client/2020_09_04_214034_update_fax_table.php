<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class UpdateFaxTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('fax', function (Blueprint $table) {
            $table->text("caller")->nullable()->change();
            $table->text("dnis")->nullable()->change();
            $table->text("file")->nullable()->change();
            $table->text("starttime")->nullable()->change();
            $table->string("faxurl")->after("id")->nullable();
            $table->string("callid")->nullable();
            $table->string("dialednumber")->nullable();
            $table->string("callerid")->nullable();
            $table->string("faxstatus")->nullable();
            $table->string("numofpages")->nullable();
            $table->string("received")->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasColumn('fax', 'faxurl')) {
            Schema::table('fax', function (Blueprint $table) {
                $table->dropColumn('faxurl');
            });
        }
        if (Schema::hasColumn('fax', 'callid')) {
            Schema::table('fax', function (Blueprint $table) {
                $table->dropColumn('callid');
            });
        }
        if (Schema::hasColumn('fax', 'dialednumber')) {
            Schema::table('fax', function (Blueprint $table) {
                $table->dropColumn('dialednumber');
            });
        }
        if (Schema::hasColumn('fax', 'callerid')) {
            Schema::table('fax', function (Blueprint $table) {
                $table->dropColumn('callerid');
            });
        }
        if (Schema::hasColumn('fax', 'faxstatus')) {
            Schema::table('fax', function (Blueprint $table) {
                $table->dropColumn('faxstatus');
            });
        }
        if (Schema::hasColumn('fax', 'numofpages')) {
            Schema::table('fax', function (Blueprint $table) {
                $table->dropColumn('numofpages');
            });
        }
        if (Schema::hasColumn('fax', 'received')) {
            Schema::table('fax', function (Blueprint $table) {
                $table->dropColumn('received');
            });
        }
    }
}
