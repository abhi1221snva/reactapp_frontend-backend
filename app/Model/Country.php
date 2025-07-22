<?php

namespace App\Model;

use App\Http\Helper\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Model;
class Country extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $guarded = ['id'];
    protected $table = 'countries';

    /*
     *Fetch Campaign list
     *@param integer $id
     *@return array
     */
    public function getCountry($request)
    {
        $data = [
            'status' => 1
        ];
        $sql = "SELECT * FROM ".$this->table." WHERE status = :status order by name desc";
        $record =  DB::connection('master')->select($sql, $data);
        $data = (array)$record;
        if(!empty($data))
        {
            return array(
                'success'=> 'true',
                'message'=> 'Country detail.',
                'data'   => $data
            );
        }
        return array(
            'success'=> 'false',
            'message'=> 'Country not created.',
            'data'   => array()
        );
    }


    public function getState($request)
    {
        try
        {
            $data =array();
             $data['country_id'] = $request->country_id;
            $sql = "SELECT * FROM states WHERE country_id = :country_id order by name desc";
            $record =  DB::connection('master')->select($sql, $data);
            $data = (array)$record;
            if(!empty($data))
            {
                return array(
                    'success'=> 'true',
                    'message'=> 'states detail.',
                    'data'   => $data
                );
            }
            return array(
                'success'=> 'false',
                'message'=> 'states not created.',
                'data'   => array()
            );
        }
        catch (Exception $e)
        {
            Log::log($e->getMessage());
        }
        catch (InvalidArgumentException $e)
        {
            Log::log($e->getMessage());
        }
    }

}
