<?php

namespace App\Model;

use App\Http\Helper\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Model;
use Maatwebsite\Excel\Facades\Excel;

class Ivr extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $guarded = ['id'];
    protected $table = 'ivr';
    /*
     *Fetch dnc list
     *@param integer $id
     *@return array
     */
    public function ivrDetail($request)
    {
        try {



            $data = array();
            $searchStr = array();
            if ($request->has('auto_id') && is_numeric($request->input('auto_id'))) {
                array_push($searchStr, 'id = :id');
                $data['id'] = $request->input('auto_id');
            }

            $str = !empty($searchStr) ? "  WHERE " . implode(" AND ", $searchStr) : '';

            $sql = "SELECT * FROM " . $this->table . $str;

            //$sql = "SELECT * FROM ".$this->table;
            $record =  DB::connection('mysql_' . $request->auth->parent_id)->select($sql, $data);
            $data = (array)$record;
            $totalRows = count($data);
            if ($request->has('start') && $request->has('limit')) {
                $start = (int)$request->input('start'); // Start index (0-based)
                $limit = (int)$request->input('limit'); // Limit number of records to fetch

                // Show all data if start is 0 and limit is provided
                if ($start == 0 && $limit > 0) {
                    $data = array_slice($data, 0, $limit); // Fetch only the first 'limit' records
                } else {
                    // For normal pagination, calculate length from start and limit
                    $length = $limit;
                    $data = array_slice($data, $start, $length); // Fetch data from start to start+length
                }
            }

            // If no pagination parameters, return all data
            if (!$request->has('start') && !$request->has('limit')) {
                $data = $data; // No pagination, return all data

            }
            if (!empty($data)) {
                return array(
                    'success' => 'true',
                    'message' => 'IVR detail.',
                    'total_rows' => $totalRows,
                    'data'   => $data

                );
            }
            return array(
                'success' => 'false',
                'message' => 'IVR not created.',
                'data'   => array()
            );
        } catch (Exception $e) {
            Log::log($e->getMessage());
        } catch (InvalidArgumentException $e) {
            Log::log($e->getMessage());
        }
    }


    public function ivrDetail_old_copy($request)
    {
        try {



            $data = array();
            $searchStr = array();
            if ($request->has('auto_id') && is_numeric($request->input('auto_id'))) {
                array_push($searchStr, 'id = :id');
                $data['id'] = $request->input('auto_id');
            }

            $str = !empty($searchStr) ? "  WHERE " . implode(" AND ", $searchStr) : '';

            $sql = "SELECT * FROM " . $this->table . $str;

            //$sql = "SELECT * FROM ".$this->table;
            $record =  DB::connection('mysql_' . $request->auth->parent_id)->select($sql, $data);
            $data = (array)$record;
            if (!empty($data)) {
                return array(
                    'success' => 'true',
                    'message' => 'IVR detail.',
                    'data'   => $data
                );
            }
            return array(
                'success' => 'false',
                'message' => 'IVR not created.',
                'data'   => array()
            );
        } catch (Exception $e) {
            Log::log($e->getMessage());
        } catch (InvalidArgumentException $e) {
            Log::log($e->getMessage());
        }
    }

    /*
     *Update dnc details
     *@param object $request
     *@return array
     */
    public function ivrUpdate($request)
    {
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

                if ($request->has('language') && !empty($request->input('language'))) {
                    array_push($updateString, 'language = :language');
                    $data['language'] = $request->input('language');
                }

                if ($request->has('voice_name') && !empty($request->input('voice_name'))) {
                    array_push($updateString, 'voice_name = :voice_name');
                    $data['voice_name'] = $request->input('voice_name');
                }

                if ($request->has('speech_text') && !empty($request->input('speech_text'))) {
                    array_push($updateString, 'speech_text = :speech_text');
                    $data['speech_text'] = $request->input('speech_text');
                }

                if ($request->has('prompt_option')) {
                    array_push($updateString, 'prompt_option = :prompt_option');
                    $data['prompt_option'] = $request->input('prompt_option');
                }

                if ($request->has('speed')) {
                    array_push($updateString, 'speed = :speed');
                    $data['speed'] = $request->input('speed');
                }

                if ($request->has('pitch')) {
                    array_push($updateString, 'pitch = :pitch');
                    $data['pitch'] = $request->input('pitch');
                }

                if (!empty($updateString) && !empty($data)) {
                    $query = "UPDATE " . $this->table . " set " . implode(" , ", $updateString) . " WHERE id = :id";
                    $save = DB::connection('mysql_' . $request->auth->parent_id)->update($query, $data);

                    //update ivr menu ivr_table id

                    $data_ivr_menu['id'] = $data['id'];
                    $data_ivr_menu['ivr_id'] = $data['ivr_id'];

                    $sql = "UPDATE ivr_menu SET ivr_id = :ivr_id  WHERE ivr_table_id = :id";
                    DB::connection('mysql_' . $request->auth->parent_id)->update($sql, $data_ivr_menu);


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

    /*
     *Add dnc details
     *@param object $request
     *@return array
     */
    public function addIvr($request)
    {
        try {
            if ($request->has('ivr_id') && !empty($request->input('ivr_id'))) {
                $data['ivr_id'] = $request->input('ivr_id');
                $data['ann_id'] = $request->input('ann_id');
                $data['ivr_desc'] = $request->input('ivr_desc');
                $data['language'] = $request->input('language');
                $data['voice_name'] = $request->input('voice_name');
                $data['speech_text'] = $request->input('speech_text');
                $data['prompt_option'] = $request->input('prompt_option');
                $data['speed'] = $request->input('speed');
                $data['pitch'] = $request->input('pitch');


                $query = "INSERT INTO " . $this->table . " (ivr_id, ann_id, ivr_desc, language, voice_name, speech_text, prompt_option,speed,pitch) VALUE (:ivr_id, :ann_id, :ivr_desc, :language, :voice_name, :speech_text,:prompt_option,:speed,:pitch)";
                $add =  DB::connection('mysql_' . $request->auth->parent_id)->insert($query, $data);
                if ($add == 1) {
                    return array(
                        'success' => 'true',
                        'message' => 'Ivr added successfully.'
                    );
                } else {
                    return array(
                        'success' => 'false',
                        'message' => 'Ivr are not added successfully.'
                    );
                }
            }

            return array(
                'success' => 'false',
                'message' => 'Ivr are not added successfully.'
            );
        } catch (Exception $e) {
            Log::log($e->getMessage());
        } catch (InvalidArgumentException $e) {
            Log::log($e->getMessage());
        }
    }

    /*
     *Update dnc details
     *@param object $request
     *@return array
     */
    public function ivrDelete($request)
    {
        try {
            if ($request->has('auto_id') && is_numeric($request->input('auto_id'))) {
                $data['id'] = $request->input('auto_id');
                $query = "DELETE FROM " . $this->table . " WHERE id = :id";
                $save =  DB::connection('mysql_' . $request->auth->parent_id)->update($query, $data);
                if ($save == 1) {
                    return array(
                        'success' => 'true',
                        'message' => 'Ivr deleted successfully.'
                    );
                } else {
                    return array(
                        'success' => 'false',
                        'message' => 'Ivr are not deleted successfully.'
                    );
                }
            }
            return array(
                'success' => 'false',
                'message' => 'Ivr doesn\'t exist.'
            );
        } catch (Exception $e) {
            Log::log($e->getMessage());
        } catch (InvalidArgumentException $e) {
            Log::log($e->getMessage());
        }
    }
}
