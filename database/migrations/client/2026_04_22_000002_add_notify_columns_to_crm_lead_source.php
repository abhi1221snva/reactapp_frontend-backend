<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_lead_source', function (Blueprint $table) {
            $table->boolean('notify_email')->default(false)->after('webhook_secret');
            $table->boolean('notify_sms')->default(false)->after('notify_email');
            $table->json('notify_user_ids')->nullable()->after('notify_sms');
        });
    }

    public function down(): void
    {
        Schema::table('crm_lead_source', function (Blueprint $table) {
            $table->dropColumn(['notify_email', 'notify_sms', 'notify_user_ids']);
        });
    }
};
