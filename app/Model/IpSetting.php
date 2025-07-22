<?php

namespace App\Model;

use App\Http\Helper\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Model;
use Maatwebsite\Excel\Facades\Excel;

class IpSetting extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $guarded = ['id'];

    protected $table = 'ip_setting';

    /*
     *Fetch dnc list
     *@param integer $id
     *@return array
     */
    public function ipSettingDetail($request)
    {
        try
        {
            $data = array();
            $searchStr = array();
            if($request->has('ip_id') && is_numeric($request->input('ip_id')))
            {
                array_push($searchStr, 'ip_id = :ip_id');
                $data['ip_id'] = $request->input('ip_id');
            }

            $str = !empty($searchStr) ? "  WHERE ".implode(" AND ", $searchStr) : '';
            $sql = "SELECT * FROM ".$this->table.$str;
            $record =  DB::connection('mysql_'.$request->auth->parent_id)->select($sql, $data);
            $data = (array)$record;
            if(!empty($data))
            {
                return array(
                    'success'=> 'true',
                    'message'=> 'Ip Setting detail.',
                    'data'   => $data
                );
            }
            return array(
                'success'=> 'false',
                'message'=> 'Ip Setting not created.',
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

    /*
     *Update dnc details
     *@param object $request
     *@return array
     */
    public function ipSettingUpdate($request)
    {
        try
        {
            if($request->has('ip_id') && is_numeric($request->input('ip_id')))
            {
                $updateString = array();
                $data['ip_id'] = $request->input('ip_id');
                if($request->has('ip_address') && $request->input('ip_address')) {
                    array_push($updateString, 'ip_address = :ip_address');
                    $data['ip_address'] = $request->input('ip_address');
                }
               if($request->has('location') && $request->input('location')) {
                    array_push($updateString, 'location = :location');
                    $data['location'] = $request->input('location');
                }

                 if($request->has('description') && $request->input('description')) {
                    array_push($updateString, 'description = :description');
                    $data['description'] = $request->input('description');
                }

                 if($request->has('status') && $request->input('status')) {
                    array_push($updateString, 'status = :status');
                    $data['status'] = $request->input('status');
                }


                if(!empty($updateString) && !empty($data))
                {
                    $query = "UPDATE ".$this->table." set ".implode(" , ", $updateString)." WHERE ip_id = :ip_id";
                    $save =  DB::connection('mysql_'.$request->auth->parent_id)->update($query, $data);
                    if($save == 1)
                    {
                        return array(
                            'success'=> 'true',
                            'message'=> 'Ip Setting updated successfully.'
                        );
                    }
                    else
                    {
                        return array(
                            'success'=> 'false',
                            'message'=> 'Ip Setting are not updated successfully.'
                        );
                    }
                }

            }
            return array(
                'success'=> 'false',
                'message'=> 'Dnc doesn\'t exist.'
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
    /*
     *Add dnc details
     *@param object $request
     *@return array
     */
    public function addIpSetting($request)
    {
        try
        {
            if($request->has('ip_address') && !empty($request->input('ip_address'))) {
                $data['ip_address'] = $request->input('ip_address');
                $data['status'] = $request->input('status');

                $data['location'] = ($request->has('location') && !empty($request->input('location'))) ? $request->input('location') :"";
                $data['description'] = ($request->has('description') && !empty($request->input('description'))) ? $request->input('description') :"";
                $query = "INSERT INTO ".$this->table." (ip_address, location, description, status) VALUE (:ip_address, :location, :description, :status)";
                $add =  DB::connection('mysql_'.$request->auth->parent_id)->insert($query, $data);
                if($add == 1)
                {
                    return array(
                        'success'=> 'true',
                        'message'=> 'Ip Setting added successfully.'
                    );
                }
                else
                {
                    return array(
                        'success'=> 'false',
                        'message'=> 'Ip Setting are not added successfully.'
                    );
                }
            }

            return array(
                'success'=> 'false',
                'message'=> 'Dnc are not added successfully.'
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
    /*
     *Update dnc details
     *@param object $request
     *@return array
     */
    public function ipSettingDelete($request)
    {
        try
        {
            if($request->has('ip_id') && is_numeric($request->input('ip_id')))
            {
                $data['ip_id'] = $request->input('ip_id');
                $query = "DELETE FROM ".$this->table." WHERE ip_id = :ip_id";
                $save =  DB::connection('mysql_'.$request->auth->parent_id)->update($query, $data);
                if($save == 1)
                {
                    return array(
                        'success'=> 'true',
                        'message'=> 'Ip setting deleted successfully.'
                    );
                }
                else
                {
                    return array(
                        'success'=> 'false',
                        'message'=> 'Ip Setting are not deleted successfully.'
                    );
                }

            }
            return array(
                'success'=> 'false',
                'message'=> 'Ip Setting doesn\'t exist.'
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



        public function uploadDnc($request, $filePath)
    {
        try
        {
            if
            (!empty($filePath))
            {
                $dataBase = 'mysql_'.$request->auth->parent_id;
                try
                {
                    $reader = Excel::toArray(new Excel(), $filePath);
                }
                catch (\Exception $e)
                {
                    return array(
                        'success'=> 'false',
                        'message'=> 'Unable to read excel.'
                    );
                }



                        if(!empty($reader))
                        {
                            $count = 0;
                            foreach ($reader as $row)
                            {
                                $i=0;
                                 foreach ($row as $item=>$value)
                                    {
                                        if($item!=0){
                               $data['number'] = $value[0];
                               $data['extension'] = $value[1];
                               $data['comment'] = $value[2];
                               $data['updated_at'] = $value[3];

                               //echo "<pre>";print_r($data);

                            $query = "INSERT INTO ".$this->table." (number, extension, comment,updated_at) VALUE (:number, :extension, :comment,:updated_at)";
                $add =  DB::connection('mysql_'.$request->auth->parent_id)->insert($query, $data);

                            }

                                    }
                                }


                                if($add == 1)
                {
                    return array(
                        'success'=> 'true',
                        'message'=> 'Dnc added successfully.'
                    );
                }




                        }
                        else
                        {
                            return array(
                                'success'=> 'false',
                                'message'=> 'DNC not added successfully, File is empty',

                            );
                        }


            }

            return array(
                'success'=> 'false',
                'message'=> 'ExcludeNumber are not added successfully.'
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
