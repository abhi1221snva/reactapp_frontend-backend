<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class AddAgentAssociateCrmLeadPermissions extends Migration
{
    /**
     * Grant agent (role_id=2) and associate (role_id=4) access to CRM lead
     * routes so they can view/edit leads, upload documents, see field
     * definitions, activity timelines, labels and lists.
     */
    public function up()
    {
        $groups = ['leads', 'crm.lead_fields', 'crm.lead_status', 'lead_activity', 'labels', 'lists'];

        foreach ([2, 4] as $roleId) {
            foreach ($groups as $groupKey) {
                $exists = DB::connection('master')
                    ->table('role_route_permissions')
                    ->where('role_id', $roleId)
                    ->where('route_group_key', $groupKey)
                    ->exists();

                if (!$exists) {
                    DB::connection('master')->table('role_route_permissions')->insert([
                        'role_id'         => $roleId,
                        'route_group_key' => $groupKey,
                    ]);
                }
            }
        }
    }

    public function down()
    {
        $groups = ['leads', 'crm.lead_fields', 'crm.lead_status', 'lead_activity', 'labels', 'lists'];

        foreach ([2, 4] as $roleId) {
            DB::connection('master')
                ->table('role_route_permissions')
                ->where('role_id', $roleId)
                ->whereIn('route_group_key', $groups)
                ->delete();
        }
    }
}
