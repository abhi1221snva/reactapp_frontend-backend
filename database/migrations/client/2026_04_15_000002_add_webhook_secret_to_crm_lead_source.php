<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AddWebhookSecretToCrmLeadSource extends Migration
{
    public function up()
    {
        if (!Schema::hasColumn('crm_lead_source', 'webhook_secret')) {
            Schema::table('crm_lead_source', function (Blueprint $table) {
                $table->char('webhook_secret', 64)->nullable()->unique()->after('unique_id');
            });
        }

        // Back-fill existing rows with a UUID secret
        $rows = DB::table('crm_lead_source')->whereNull('webhook_secret')->orderBy('id')->get();
        foreach ($rows as $row) {
            DB::table('crm_lead_source')
                ->where('id', $row->id)
                ->update(['webhook_secret' => (string) Str::uuid()]);
        }
    }

    public function down()
    {
        Schema::table('crm_lead_source', function (Blueprint $table) {
            $table->dropColumn('webhook_secret');
        });
    }
}
