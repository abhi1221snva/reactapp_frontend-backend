<?php


namespace App\Services;


use App\Model\Master\Client;
use Illuminate\Support\Facades\Cache;

class ClientService
{
    const CACHE_KEY = 'clients';

    public static function cache()
    {
        $models = Client::all();
        $values = [];
        foreach ( $models as $model ) {
            $values[$model->id]["company_name"] = $model->company_name;
            $values[$model->id]["logo"] = $model->logo;
            $values[$model->id]["mca_crm"] = $model->mca_crm;

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
        $clients = Cache::get(self::CACHE_KEY);
        if (isset($clients[$id])) {
            return $clients[$id];
        }
        $clients = self::cache();
        return $clients[$id];
    }
}
