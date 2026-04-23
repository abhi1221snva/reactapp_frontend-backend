<?php


namespace App\Services;


use Illuminate\Support\Facades\DB;

class ExtensionGroupService
{
    public static function getExtensionGroups(int $clientId, int $extension)
    {
        $sql = "SELECT id FROM extension_group WHERE id IN (SELECT group_id FROM extension_group_map WHERE extension = ? AND is_deleted = 0) AND status = 1 AND is_deleted = 0";
        $records = DB::connection("mysql_$clientId")->select($sql, [$extension]);
        $data = [0];
        foreach ($records as $record) {
            $data[] = $record->id;
        }
        return $data;
    }

    public static function getExtensionsByGroups(int $clientId, array $groupIds = [0])
    {
        if (empty($groupIds)) return [];

        // Filter to integers only for safety, then use parameterized placeholders
        $groupIds = array_map('intval', $groupIds);
        $placeholders = implode(',', array_fill(0, count($groupIds), '?'));
        $sql = "SELECT DISTINCT extension as ext FROM extension_group_map WHERE group_id IN ($placeholders) AND is_deleted = 0";
        $records = DB::connection("mysql_$clientId")->select($sql, $groupIds);
        $data = [];
        foreach ($records as $record) {
            $data[] = $record->ext;
        }
        return $data;
    }
}
