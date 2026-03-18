<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddEmailTypeToCrmEmailTemplatesTable extends Migration
{
    public function up()
    {
        Schema::table('crm_email_templates', function (Blueprint $table) {
            $table->string('email_type', 50)->default('general')->after('send_bcc')
                ->comment('Template category: general, online_application, etc.');
        });
    }

    public function down()
    {
        Schema::table('crm_email_templates', function (Blueprint $table) {
            $table->dropColumn('email_type');
        });
    }
}
