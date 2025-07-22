<?php

namespace App\Model;

use App\Http\Helper\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Model;
class Group extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $guarded = ['id'];

    /*
     * Fetch groups list
     *@param integer $id
     * @return array
     */
    public function groupDetail(int $clientId, int $groupId = null)
    {
        $searchStr = array('is_deleted = :is_deleted');
        $data['is_deleted'] = 0;
        if($groupId) {
            array_push($searchStr, 'id = :id');
            $data['id'] = $groupId;
        }

        $sql = "SELECT * FROM extension_group WHERE ".implode(" AND ", $searchStr);
        $record =  DB::connection("mysql_$clientId")->select($sql, $data);
        return (array)$record;
    }

    /*
     * Update group details
     *@param object $request
     * @return array
     */
    public function groupUpdate($request)
    {
        try
        {
            if($request->has('group_id') && is_numeric($request->input('group_id')))
            {
                $updateString = array();
                if($request->has('title') && !empty($request->input('title'))) {
                    array_push($updateString, 'title = :title');
                    $data['title'] = $request->input('title');
                }
                if($request->has('status') && is_numeric($request->input('status'))) {
                    array_push($updateString, 'status = :status');
                    $data['status'] = $request->input('status');
                }
                if($request->has('is_deleted') && is_numeric($request->input('is_deleted'))) {
                    array_push($updateString, 'is_deleted = :is_deleted');
                    $data['is_deleted'] = $request->input('is_deleted');
                }
                if(!empty($updateString) && !empty($data))
                {
                    $data['id'] = $request->input('group_id');
                    $query = "UPDATE extension_group set ".implode(" , ", $updateString)." WHERE id = :id";
                    $save =  DB::connection('mysql_'.$request->auth->parent_id)->update($query, $data);
                    if($save == 1)
                    {
                        return array(
                            'success'=> 'true',
                            'message'=> 'Groups updated successfully.'
                        );
                    }
                    else
                    {
                        return array(
                            'success'=> 'false',
                            'message'=> 'Groups are not updated successfully.'
                        );
                    }
                }

            }
            return array(
                'success'=> 'false',
                'message'=> 'Groups doesn\'t exist.'
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
     *Add group details
     *@param object $request
     * @return array
     */
    public function addGroup($request)
    {
        try
        {
            if($request->has('title') && !empty($request->input('title'))) {
                $data['title'] = $request->input('title');
                $query = "INSERT INTO extension_group (title) VALUE (:title)";
                $add =  DB::connection('mysql_'.$request->auth->parent_id)->update($query, $data);
                if($add == 1)
                {
                    $newAdd =  DB::connection('mysql_'.$request->auth->parent_id)->selectOne("SELECT * FROM extension_group ORDER BY id DESC ", array());
                    $newAdd = (array)$newAdd;
                    return array(
                        'success'=> 'true',
                        'message'=> 'Groups added successfully.',
                        'data'   => $newAdd
                    );
                }
                else
                {
                    return array(
                        'success'=> 'false',
                        'message'=> 'Groups are not added successfully.'
                    );
                }
            }

            return array(
                'success'=> 'false',
                'message'=> 'Groups are not added successfully.'
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
