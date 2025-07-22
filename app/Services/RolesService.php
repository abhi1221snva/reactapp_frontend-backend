<?php


namespace App\Services;

use App\Model\Role;
use Illuminate\Support\Facades\Cache;

class RolesService
{
    const CACHE_KEY = 'roles';

    public static function cache()
    {
        $models = Role::all();
        $values = [];
        foreach ( $models as $model ) {
            $values[$model->id] = [
                "name" => $model->name,
                "level" => $model->level
            ];
        }
        Cache::forever(self::CACHE_KEY, $values);
        return $values;
    }

    public static function clearCache()
    {
        Cache::forget(self::CACHE_KEY);
    }

    public static function getById(int $id)
    {
        $roles = Cache::get(self::CACHE_KEY);
        if (isset($roles[$id])) {
            return $roles[$id];
        }
        $roles = self::cache();
        return $roles[$id];
    }

    public static function getRolesForLevel(int $level)
    {
        $roles = Role::where("level", "<=", $level)->orderBy('level', 'DESC')->get()->all();
        $result = [];
        foreach ($roles as $role) $result[$role["id"]] = $role["name"];
        return $result;
    }
}
