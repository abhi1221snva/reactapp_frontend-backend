<?php

namespace App\Model;

use App\Http\Helper\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Model;
use Maatwebsite\Excel\Facades\Excel;

class Dest extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $guarded = ['id'];
    protected $table = 'dest_type_list';
    /*
     *Fetch dnc list
     *@param integer $id
     *@return array
     */
    public function destDetail($request)
    {
        try
        {
            $data = array();

            $data['is_deleted'] = '0';
           
            
            $sql = "SELECT * FROM ".$this->table." WHERE is_deleted = :is_deleted";
            $record =  DB::connection('master')->select($sql, $data);
            $data = (array)$record;
            if(!empty($data))
            {
                return array(
                    'success'=> 'true',
                    'message'=> 'Dest detail.',
                    'data'   => $data
                );
            }
            return array(
                'success'=> 'false',
                'message'=> 'Dest not created.',
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
