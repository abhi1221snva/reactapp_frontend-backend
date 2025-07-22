<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddColumnInUserExtensions extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('user_extensions', function (Blueprint $table) {

            if (!Schema::hasColumn('user_extensions', 'icesupport')) 
            {
                $table->char('icesupport', 6)->nullable();
            } 

            if (!Schema::hasColumn('user_extensions', 'force_avp')) 
            {
                $table->char('force_avp', 6)->nullable();
            } 

            if (!Schema::hasColumn('user_extensions', 'dtlsenable')) 
            {
                $table->char('dtlsenable', 6)->nullable();
            } 

            if (!Schema::hasColumn('user_extensions', 'dtlsverify')) 
            {
                $table->string('dtlsverify', 30)->nullable();
            } 

             if (!Schema::hasColumn('user_extensions', 'dtlscertfile')) 
            {
                $table->string('dtlscertfile', 30)->nullable();
            } 

             if (!Schema::hasColumn('user_extensions', 'dtlssetup')) 
            {
                $table->string('dtlssetup', 30)->nullable();
            } 

            if (!Schema::hasColumn('user_extensions', 'rtcp_mux')) 
            {
                $table->char('rtcp_mux', 6)->nullable();
            } 

            if (!Schema::hasColumn('user_extensions', 'avpf')) 
            {
                $table->char('avpf', 6)->nullable();
            } 

            if (!Schema::hasColumn('user_extensions', 'webrtc')) 
            {
                $table->char('webrtc', 6)->nullable();
            } 
            
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('user_extensions', function (Blueprint $table) {
            $table->dropColumn(["icesupport"]);
            $table->dropColumn(["force_avp"]);
            $table->dropColumn(["dtlsenable"]);
            $table->dropColumn(["dtlsverify"]);
            $table->dropColumn(["dtlscertfile"]);
            $table->dropColumn(["dtlssetup"]);
            $table->dropColumn(["rtcp_mux"]);
            $table->dropColumn(["avpf"]);
            $table->dropColumn(["webrtc"]);
            
        });
    }
}
