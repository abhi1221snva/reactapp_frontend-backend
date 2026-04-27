<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class DropCrmNotificationsLeadIdForeignKey extends Migration
{
    public function up()
    {
        Schema::table('crm_notifications', function (Blueprint $table) {
            // Drop FK that references old crm_lead_data table (pre-EAV migration)
            $table->dropForeign('crm_notifications_lead_id_foreign');
        });
    }

    public function down()
    {
        // No-op — re-adding this FK would be wrong since crm_lead_data is legacy
    }
}
