<?php


namespace App\Services;


use Illuminate\Support\Facades\DB;

class ExtensionGroupService
{
    public static function getExtensionGroups(int $clientId, int $extension)
    {
        $sql = "SELECT id FROM extension_group WHERE id IN (SELECT group_id from extension_group_map WHERE extension=$extension AND is_deleted=0) AND status=1 AND is_deleted=0";
        $records = DB::connection("mysql_$clientId")->select($sql);
        $data = [0];
        foreach ($records as $record) {
            array_push($data, $record->id);
        }
        return $data;
    }

    public static function getExtensionsByGroups(int $clientId, array $groupIds = [0])
    {
        if (empty($groupIds)) return [];

        $sql = "SELECT DISTINCT extension as ext FROM extension_group_map WHERE group_id IN (".implode(",", $groupIds).") AND is_deleted=0";
        $records = DB::connection("mysql_$clientId")->select($sql);
        $data = [];
        foreach ($records as $record) {
            array_push($data, $record->ext);
        }
        return $data;
    }
}
