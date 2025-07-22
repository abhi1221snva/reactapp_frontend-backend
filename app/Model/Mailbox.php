<?php

namespace App\Model;

use App\Http\Helper\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Model;

class Mailbox extends Model {

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $guarded = ['id'];

    /*
     * Fetch CDR from user id
     * @param integer $id
     * @return array
     */

    public function getMailbox($request) {
        try {
            $id = $request->input('id');
            if (!empty($id) && is_numeric($id)) {
                $search = array();
                $searchString = array();
                $limitString = '';
                $search['extension'] = $request->auth->extension;
                array_push($searchString, 'extension = :extension');
                
                if ($request->has('extension') && !empty($request->input('extension'))) {
                    $search['extension'] = $request->input('extension');
                    array_push($searchString, 'extension = :extension');
                }

                if ($request->has('start_date') && $request->has('end_date') && !empty($request->input('start_date')) && !empty($request->input('end_date'))) {
                    $start = date('Y-m-d', strtotime($request->input('start_date'))) . " 00:00:00";
                    $end = date('Y-m-d', strtotime($request->input('end_date'))) . " 23:59:59";
                    $search['start_time'] = $start;
                    $search['end_time'] = $end;
                    array_push($searchString, 'date_time BETWEEN :start_time AND :end_time');
                }

                if ($request->has('lower_limit') && $request->has('upper_limit') && is_numeric($request->input('lower_limit')) && is_numeric($request->input('upper_limit'))) {
                    $search['lower_limit'] = $request->input('lower_limit');
                    $search['upper_limit'] = $request->input('upper_limit');
                    $limitString = "LIMIT :lower_limit , :upper_limit";
                }
                $filter = (!empty($searchString)) ? " WHERE " . implode(" AND ", $searchString) : '';
                $sql = "SELECT 
                          SQL_CALC_FOUND_ROWS id,ani,vm_file_location,status,extension,date_time FROM mailbox "
                        . $filter . " ORDER BY date_time DESC " . $limitString;
                $record = DB::connection('mysql_' . $request->auth->parent_id)->select($sql, $search);
                $recordCount = DB::connection('mysql_' . $request->auth->parent_id)->selectOne("SELECT FOUND_ROWS() as count");
                $recordCount = (array) $recordCount;
                if (!empty($record)) {
                    $data = (array) $record;
                    return array(
                        'success' => 'true',
                        'message' => 'MailBox Report.',
                        'record_count' => $recordCount['count'],
                        'data' => $data
                    );
                } else {
                    return array(
                        'success' => 'true',
                        'message' => 'No MailBox Report found.',
                        'record_count' => 0,
                        'data' => array()
                    );
                }
            }
            return array(
                'success' => 'false',
                'message' => 'MailBox Report doesn\'t exist.'
            );
        } catch (Exception $e) {
            Log::log($e->getMessage());
        } catch (InvalidArgumentException $e) {
            Log::log($e->getMessage());
        }
    }

    public function updateMailBox($request) {
        try {
            if ($request->has('mailbox_id') && is_numeric($request->input('mailbox_id'))) {
                $updateString = array();
                $data['id'] = $request->input('mailbox_id');
                if ($request->has('status') && is_numeric($request->input('status'))) {
                    array_push($updateString, 'status = :status');
                    $data['status'] = $request->input('status');
                }

                //echo "<pre>";print_r($updateString);die;
                //echo $updateString;die;

                if (!empty($updateString) && !empty($data)) {
                    $query = "UPDATE mailbox set " . implode(" , ", $updateString) . " WHERE id = :id";
                    $save = DB::connection('mysql_' . $request->auth->parent_id)->update($query, $data);
                    if ($save == 1) {
                        return array(
                            'success' => 'true',
                            'message' => 'mailbox updated successfully.'
                        );
                    } else {
                        return array(
                            'success' => 'false',
                            'message' => 'mailbox are not updated successfully.'
                        );
                    }
                }
            }
            return array(
                'success' => 'false',
                'message' => 'mailbox doesn\'t exist.'
            );
        } catch (Exception $e) {
            Log::log($e->getMessage());
        } catch (InvalidArgumentException $e) {
            Log::log($e->getMessage());
        }
    }

    /*
     * Fetch Live calls from user id
     * @param integer $id
     * @return array
     */


    /*
     * Fetch Call transfer Detail
     * @param integer $id
     * @return array
     */

    public function getUnreadMailBox($request) {
        try {
            $id = $request->input('id');
            if (!empty($id) && is_numeric($id)) {
                $search = array();
                $searchString = array();
                $limitString = '';


                /* if($request->auth->role == 2)
                  {
                  $search['extension'] = $request->auth->extension;
                  array_push($searchString, 'extension = :extension');
                  }
                  else */
                //if($request->auth->role == 2)
                //{
                /* $search['extension'] = $request->auth->extension;
                  array_push($searchString, 'extension = :extension'); */
                //}
                //else
                if ($request->has('extension') && !empty($request->input('extension'))) {
                    $search['extension'] = $request->input('extension');
                    array_push($searchString, 'extension = :extension');

                    $search['status'] = '1';
                    array_push($searchString, 'status = :status');
                }

                // return $search;


                $sql = "SELECT count(1) as rowCountMailbox FROM mailbox where status='1' and extension ='" . $request->input('extension') . "' ";
                // $record =  DB::connection('master')->selectOne($sql, $data);
                $record = DB::connection('mysql_' . $request->auth->parent_id)->select($sql);

                $response = (array) $record;

                foreach ($response as $res) {
                    $dataMailbox['record_count'] = $res->rowCountMailbox;
                }
                if (!empty($response)) {
                    $data = (array) $record;
                    return array(
                        'success' => 'true',
                        'message' => 'MailBox Report.',
                        'data' => $dataMailbox,
                    );
                } else {
                    return array(
                        'success' => 'true',
                        'message' => 'No MailBox Report found.',
                        'record_count' => 0,
                        'data' => array()
                    );
                }
            }
            return array(
                'success' => 'false',
                'message' => 'MailBox Report doesn\'t exist.'
            );
        } catch (Exception $e) {
            Log::log($e->getMessage());
        } catch (InvalidArgumentException $e) {
            Log::log($e->getMessage());
        }
    }

    public function deleteMailbox($request) {
        try {
            if ($request->has('mailbox_id')) {
                $data['id'] = $request->input('mailbox_id');
                $query = "DELETE FROM mailbox WHERE id IN (".$data['id'].")";
                $save = DB::connection('mysql_' . $request->auth->parent_id)->update($query);
                if ($save == 1) {
                    return array(
                        'success' => 'true',
                        'message' => 'Mailbox deleted successfully.'
                    );
                } else {
                    return array(
                        'success' => 'false',
                        'message' => 'Mailbox are not deleted successfully.'
                    );
                }
            }
            return array(
                'success' => 'false',
                'message' => 'Mailbox doesn\'t exist.'
            );
        } catch (Exception $e) {
            Log::log($e->getMessage());
        } catch (InvalidArgumentException $e) {
            Log::log($e->getMessage());
        }
    }

}
