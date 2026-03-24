<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddStatusAndMetaToCrmSmtpSettingTable extends Migration
{
    public function up()
    {
        Schema::table('crm_smtp_setting', function (Blueprint $table) {
            if (!Schema::hasColumn('crm_smtp_setting', 'status')) {
                $table->tinyInteger('status')->default(1)->after('mail_type');
            }
            if (!Schema::hasColumn('crm_smtp_setting', 'meta_json')) {
                $table->text('meta_json')->nullable()->after('status');
            }
        });
    }

    public function down()
    {
        Schema::table('crm_smtp_setting', function (Blueprint $table) {
            $table->dropColumn(['status', 'meta_json']);
        });
    }
}
