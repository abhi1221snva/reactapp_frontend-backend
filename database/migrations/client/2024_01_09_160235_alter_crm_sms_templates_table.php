<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AlterCrmSmsTemplatesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('crm_sms_templates', function (Blueprint $table) {
            \DB::statement("ALTER TABLE crm_sms_templates MODIFY lead_status VARCHAR(50) DEFAULT NULL;
            ");
        
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('crm_sms_templates', function (Blueprint $table) {
            //
        });
    }
}
