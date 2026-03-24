<?php

namespace App\Model;

use App\Http\Helper\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Model;
use Maatwebsite\Excel\Facades\Excel;

class IvrMenu extends Model {

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $guarded = ['id'];
    protected $table = 'ivr_menu';

    /*
     * Fetch dnc list
     * @param integer $id
     * @return array
     */
    public function ivrMenuDetail($request) {
        try {
            $data = array();
            $searchStr = array();
            //if ivr id is passed then return ivr menu data else all ivr menus
            if ($request->has('ivr_id') && $request->input('ivr_id')) {
                array_push($searchStr, 'IV.ivr_id = :ivr_id');
                $data['ivr_id'] = $request->input('ivr_id');
            }
            $str = !empty($searchStr) ? "  WHERE " . implode(" AND ", $searchStr) : '';

            $sql = "SELECT IV.ivr_desc, IV.id, IV.ivr_id, IM.id AS ivr_m_id,IM.dtmf, IM.dest_type, IM.dest,IM.dtmf_title, IM.is_deleted "
                    . " FROM ivr IV  "
                    . " LEFT JOIN ivr_menu IM ON IV.ivr_id = IM.ivr_id "
                    . " $str "
                    . " ORDER BY IV.id ASC, IM.dtmf ASC";

            $record = DB::connection('mysql_' . $request->auth->parent_id)->select($sql, $data);
            $data = (array) $record;
            if (!empty($data)) {
                return array(
                    'success' => 'true',
                    'message' => 'IVR Menu detail.',
                    'data' => $data
                );
            }
            return array(
                'success' => 'false',
                'message' => 'IVR Menu not created.',
                'data' => array()
            );
        } catch (Exception $e) {
            Log::log($e->getMessage());
        } catch (InvalidArgumentException $e) {
            Log::log($e->getMessage());
        }
    }
    
    /*
    * Add / edit ivr menu
    * @param object $request
    * @return array
    */
    public function editIvrMenu($request) {

        //return $request->all();
        try {
            $ivr = 0;
            $arrParam = [];
            if (is_array($request->input('parameter'))) {
                foreach($request->input('parameter') as $key => $val) {
                    switch($key) {
                        case 'ivr':
                            $ivr = $val;
                        break;
                        case 'dtmf':
                            $arrParam[$key] = $val;
                        break;
                        case 'dtmf_title':
                            $arrParam[$key] = $val;
                            break;
                        case 'dest_type':
                            $arrParam[$key] = $val;
                        break;
                        case 'dest':
                            $arrParam[$key] = $val;
                        break;
                        case 'ivr_menu_id':
                            $arrParam[$key] = $val;
                        break;
                    }
                }
                
                //validation IVR is required
                if($ivr == 0) {
                    return array (
                        'success' => 'false',
                        'message' => "IVR is required to create menu"
                    );
                }
                //validate duplicate DTMF 
                foreach($arrParam['dtmf'] as $key => $val) {
                    $dupCnt = 0;
                    foreach($arrParam['dtmf'] as $key1 => $val1) {
                        if($val == $val1) {
                            $dupCnt++;
                            if($dupCnt > 1) {
                                return array (
                                    'success' => 'false',
                                    'message' => "Cannot use same DTMF in same IVR menu"
                                );
                            }
                            
                        }
                    }
                }

                
                for($i=0; $i<count($arrParam['dtmf']); $i++) {

                     $sql = "select * from ivr where id='".$ivr."'";
                     $record = DB::connection('mysql_' . $request->auth->parent_id)->selectOne($sql);
                     $ivr_id = $record->ivr_id;


                    $data['ivr_table_id'] = $ivr;

                    $data['ivr_id'] = $ivr_id;

                    $data['dtmf'] = $arrParam['dtmf'][$i];
                    $data['dtmf_title'] = $arrParam['dtmf_title'][$i];

                    $data['dest_type'] = $arrParam['dest_type'][$i];
                    $data['dest'] = $arrParam['dest'][$i];
                    if($arrParam['ivr_menu_id'][$i] > 0) {
                        $data['id'] = $arrParam['ivr_menu_id'][$i];
                        $sql = "UPDATE " . $this->table . " SET ivr_id = :ivr_id, dtmf = :dtmf, dtmf_title = :dtmf_title,  "
                                . "dest_type = :dest_type, dest = :dest,ivr_table_id=:ivr_table_id  WHERE id = :id";
                        DB::connection('mysql_' . $request->auth->parent_id)->update($sql, $data);
                        unset($data['id']);
                    } else {
                        $query = "INSERT INTO " . $this->table . " (dtmf,dtmf_title,dest_type,ivr_id,dest,ivr_table_id) VALUE (:dtmf,:dtmf_title,:dest_type,:ivr_id,:dest,:ivr_table_id)";
                        $add = DB::connection('mysql_' . $request->auth->parent_id)->update($query, $data);
                    }
                }

                return array (
                    'success' => 'true',
                    'message' => 'IVR Menu updated successfully.'
                );
            }

            return array (
                'success' => 'true',
                'message' => 'IVR Menu not updated successfully.'
            );
        } catch (Exception $e) {
            Log::log($e->getMessage());
        } catch (InvalidArgumentException $e) {
            Log::log($e->getMessage());
        }
    }
    
    /*
     * Update dnc details
     * @param object $request
     * @return array
     */

    public function ivrUpdate($request) {
        try {
            if ($request->has('auto_id') && is_numeric($request->input('auto_id'))) {
                $updateString = array();
                $data['id'] = $request->input('auto_id');



                if ($request->has('ivr_id') && !empty($request->input('ivr_id'))) {
                    array_push($updateString, 'ivr_id = :ivr_id');
                    $data['ivr_id'] = $request->input('ivr_id');
                }



                if ($request->has('ann_id') && !empty($request->input('ann_id'))) {
                    array_push($updateString, 'ann_id = :ann_id');
                    $data['ann_id'] = $request->input('ann_id');
                }

                if ($request->has('ivr_desc') && !empty($request->input('ivr_desc'))) {
                    array_push($updateString, 'ivr_desc = :ivr_desc');
                    $data['ivr_desc'] = $request->input('ivr_desc');
                }

                //echo "<pre>";print_r($updateString);die;
                if (!empty($updateString) && !empty($data)) {
                    $query = "UPDATE " . $this->table . " set " . implode(" , ", $updateString) . " WHERE id = :id";
                    $save = DB::connection('mysql_' . $request->auth->parent_id)->update($query, $data);

                    return array(
                        'success' => 'true',
                        'message' => 'Ivr updated successfully.'
                    );
                }
            }
        } catch (Exception $e) {
            Log::log($e->getMessage());
        } catch (InvalidArgumentException $e) {
            Log::log($e->getMessage());
        }
    }
public function addIvrMenu($request)
{
    try {

        $responseData = [];

        if (is_array($request->input('parameter'))) {

            foreach ($request->input('parameter') as $key => $value) {

                $data = [];
                $data['dtmf']       = $value['dtmf'] ?? null;
                $data['dest_type']  = $value['dest_type'] ?? null;
                $data['ivr_id']     = $value['ivr_id'] ?? null;
                $data['dest']       = $value['dest'] ?? null;
                $data['dtmf_title'] = $value['dtmf_title'] ?? null;

                // Insert using Query Builder (recommended)
                DB::connection('mysql_' . $request->auth->parent_id)
                    ->table($this->table)
                    ->insert($data);

                // Add inserted data to response
                $responseData[] = [
                    'ivr_id'     => $data['ivr_id'],
                    'dtmf'       => $data['dtmf'],
                    'dtmf_title' => $data['dtmf_title'],
                    'dest_type'  => $data['dest_type'],
                    'dest'       => $data['dest'],
                ];
            }

            return [
                'success' => true,
                'message' => 'IVR Menu added successfully.',
                'data'    => $responseData
            ];
        }

        return [
            'success' => false,
            'message' => 'Invalid parameter format.'
        ];

    } catch (\Exception $e) {

        Log::error('Add IVR Menu Error: ' . $e->getMessage());

        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}


    public function addIvrMenuold($request) {

        //dd($request);die;
        try {



            //echo "<pre>";print_r($request->input('parameter'));
            // echo "<pre>";print_r($request->input('parameter'));die;


            if (is_array($request->input('parameter'))) {
                foreach ($request->input('parameter') as $key => $value) {

                    // echo "<pre>";print_r($value['dtmf']);


                    $data['dtmf'] = $value['dtmf'];
                    $data['dest_type'] = $value['dest_type'];
                    $data['ivr_id'] = $value['ivr_id'];
                    $data['dest'] = $value['dest'];

                     $data['dtmf_title'] = $value['dtmf_title']; // ✅ ADD THIS

                $query = "INSERT INTO " . $this->table . " 
                (dtmf, dest_type, ivr_id, dest, dtmf_title) 
                VALUES (:dtmf, :dest_type, :ivr_id, :dest, :dtmf_title)";
                }

                return array(
                    'success' => 'true',
                    'message' => 'IVR Menu added successfully.'
                );
            } else {
                
            }


            return array(
                'success' => 'true',
                'message' => 'IVR Menu added successfully.'
            );
        } catch (Exception $e) {
            Log::log($e->getMessage());
        } catch (InvalidArgumentException $e) {
            Log::log($e->getMessage());
        }
    }

    /*
     * Update dnc details
     * @param object $request
     * @return array
     */

    public function ivrMenuDelete($request) {
        try {
            if ($request->has('auto_id') && is_numeric($request->input('auto_id'))) {
                $data['id'] = $request->input('auto_id');
                $query = "DELETE FROM " . $this->table . " WHERE id = :id";
                $save = DB::connection('mysql_' . $request->auth->parent_id)->update($query, $data);
                if ($save == 1) {
                    return array(
                        'success' => 'true',
                        'message' => 'IvrMenu has been deleted successfully.'
                    );
                } else {
                    return array(
                        'success' => 'false',
                        'message' => 'IvrMenu not deleted successfully.'
                    );
                }
            }
            return array(
                'success' => 'false',
                'message' => 'IvrMenu doesn\'t exist.'
            );
        } catch (Exception $e) {
            Log::log($e->getMessage());
        } catch (InvalidArgumentException $e) {
            Log::log($e->getMessage());
        }
    }

}
