<?php

namespace App\Model;

use App\Http\Helper\Log;
use Illuminate\Support\Facades\DB;
use App\Model\User;
use App\Model\Master\AsteriskServer;
use Illuminate\Database\Eloquent\Model;

class Label extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $guarded = ['id'];
    protected $table = 'label';
    /*
     *Fetch label list
     *@param integer $id
     *@return array
     */
   public function labelDetail($request)
{
    try {
        $data = [];
        $searchStr = [];

        // Always exclude deleted records (default is_deleted = 0)
        $searchStr[] = 'is_deleted = :is_deleted';
        $data['is_deleted'] = 0;

        // Filter by label_id
        if ($request->has('label_id') && is_numeric($request->input('label_id'))) {
            $searchStr[] = 'id = :id';
            $data['id'] = $request->input('label_id');
        }

        // Filter by title (partial match)
        if ($request->has('title') && trim($request->input('title')) !== '') {
            $searchStr[] = 'title LIKE :title';
            $data['title'] = '%' . $request->input('title') . '%';
        }

        // Build WHERE clause
        $str = " WHERE " . implode(" AND ", $searchStr);

        // SQL query
        $sql = "SELECT * FROM " . $this->table . $str . " ORDER BY display_order ASC";
        $record = DB::connection('mysql_' . $request->auth->parent_id)->select($sql, $data);
        $data = (array)$record;

        if (!empty($data)) {
            // Pagination if start & limit are provided
            if ($request->has('start') && $request->has('limit')) {
                $total_row = count($data);
                $start = (int) $request->input('start');
                $limit = (int) $request->input('limit');

                $data = array_slice($data, $start, $limit, false);

                return [
                    'success' => 'true',
                    'total'   => $total_row,
                    'message' => 'Label detail.',
                    'data'    => $data
                ];
            }

            return [
                'success' => 'true',
                'message' => 'Label detail.',
                'data'    => $data
            ];
        }

        return [
            'success' => 'false',
            'message' => 'Label not created.',
            'data'    => []
        ];

    } catch (Exception $e) {
        Log::error($e->getMessage());
    } catch (InvalidArgumentException $e) {
        Log::error($e->getMessage());
    }
}


    public function labelDetail_old_code($request)
    {
        try {
            $data = array();
            $searchStr = array();

            if ($request->has('is_deleted') && is_numeric($request->input('is_deleted'))) {
                array_push($searchStr, 'is_deleted = :is_deleted');
                $data['is_deleted'] = $request->input('is_deleted');
            }

            if ($request->has('label_id') && is_numeric($request->input('label_id'))) {
                array_push($searchStr, 'id = :id');
                $data['id'] = $request->input('label_id');
            }
            if ($request->has('extension') && is_numeric($request->input('extension'))) {
                array_push($searchStr, 'title = :title');
                $data['title'] = $request->input('title');
            }
            $str = !empty($searchStr) ? "  WHERE " . implode(" AND ", $searchStr) : '';
            $sql = "SELECT * FROM " . $this->table . $str . " order by display_order ASC";
            $record =  DB::connection('mysql_' . $request->auth->parent_id)->select($sql, $data);
            $data = (array)$record;
            if (!empty($data)) {
                return array(
                    'success' => 'true',
                    'message' => 'label detail.',
                    'data'   => $data
                );
            }
            return array(
                'success' => 'false',
                'message' => 'label not created.',
                'data'   => array()
            );
        } catch (Exception $e) {
            Log::log($e->getMessage());
        } catch (InvalidArgumentException $e) {
            Log::log($e->getMessage());
        }
    }

    /*
     *Update label details
     *@param object $request
     *@return array
     */
   public function labelUpdate($request)
{
    try {
        if ($request->has('label_id') && is_numeric($request->input('label_id'))) {
            $updateString = array();
            $data['id'] = $request->input('label_id');

            if ($request->has('title') && !empty($request->input('title'))) {
                array_push($updateString, 'title = :title');
                $data['title'] = $request->input('title');
            }

            if ($request->has('is_deleted') && is_numeric($request->input('is_deleted'))) {
                array_push($updateString, 'is_deleted = :is_deleted');
                $data['is_deleted'] = $request->input('is_deleted');

                array_push($updateString, 'status = :status');
                $data['status'] = '0';
            }

            if (!empty($updateString) && !empty($data)) {
                $query = "UPDATE " . $this->table . " SET " . implode(" , ", $updateString) . " WHERE id = :id";
                $save = DB::connection('mysql_' . $request->auth->parent_id)->update($query, $data);

                if ($save == 1) {
                    // Check if update is a delete action
                    if (isset($data['is_deleted']) && $data['is_deleted'] == 1) {
                        return [
                            'success' => 'true',
                            'message' => 'Label deleted successfully.'
                        ];
                    }

                    return [
                        'success' => 'true',
                        'message' => 'Label updated successfully.'
                    ];
                } else {
                    return [
                        'success' => 'false',
                        'message' => 'Label Already Deleted.'
                    ];
                }
            }
        }

        return [
            'success' => 'false',
            'message' => 'Label doesn\'t exist.'
        ];

    } catch (Exception $e) {
        Log::log($e->getMessage());
    } catch (InvalidArgumentException $e) {
        Log::log($e->getMessage());
    }
}

    /*
     *Add label details
     *@param object $request
     *@return array
     */
    public function addLabel($request)
    {
        try {
            if ($request->has('title') && !empty($request->input('title'))) {
                $query = "INSERT INTO " . $this->table . " (title) VALUE (:title)";
                $add =  DB::connection('mysql_' . $request->auth->parent_id)->insert($query, array('title' => $request->input('title')));
                if ($add == 1) {
                    $newAdd =  DB::connection('mysql_' . $request->auth->parent_id)->selectOne("SELECT * FROM " . $this->table . " ORDER BY id DESC ", array());
                    $newAdd = (array)$newAdd;
                    return array(
                        'success' => 'true',
                        'message' => 'Label added successfully.',
                        'data'   => $newAdd
                    );
                } else {
                    return array(
                        'success' => 'false',
                        'message' => 'Label are not added successfully.'
                    );
                }
            }

            return array(
                'success' => 'false',
                'message' => 'Label are not added successfully.'
            );
        } catch (Exception $e) {
            Log::log($e->getMessage());
        } catch (InvalidArgumentException $e) {
            Log::log($e->getMessage());
        }
    }

    // public function liveExtensionDetail($request)
    // {
    //     try {
    //         $data = array();
    //         $searchStr = array();
    //         $sql = "SELECT el.extension,el.status,el.channel,el.campaign_id,el.lead_id,campaign.title   FROM extension_live as el LEFT JOIN campaign on campaign.id=el.campaign_id";
    //         $record =  DB::connection('mysql_' . $request->auth->parent_id)->select($sql);
    //         $data = (array)$record;
    //         foreach ($data as $key => $live_extension) {
    //             $user_data = User::where('extension', $live_extension->extension)->orWhere('alt_extension', $live_extension->extension)->get()->first();
    //             if (!empty($user_data)) {
    //                 $data[$key]->full_name = $user_data->first_name . ' ' . $user_data->last_name;
    //                 $data[$key]->extension = $user_data->extension;
    //             } else {
    //                 $data[$key]->full_name = "";
    //                 $data[$key]->extension = "";
    //             }
    //         }
    //         // Apply pagination if present
    //         if ($request->has(['start', 'limit'])) {
    //             $start = (int)$request->input('start');
    //             $limit = (int)$request->input('limit');
    //             $data = array_slice($data, $start, $limit, true); // paginate array
    //         }
    //         if (!empty($data)) {
    //             return array(
    //                 'success' => 'true',
    //                 'message' => 'label detail.',
    //                 'data'   => $data
    //             );
    //         }
    //         return array(
    //             'success' => 'false',
    //             'message' => 'label not created.',
    //             'data'   => array()
    //         );
    //     } catch (Exception $e) {
    //         Log::log($e->getMessage());
    //     } catch (InvalidArgumentException $e) {
    //         Log::log($e->getMessage());
    //     }
    // }
public function liveExtensionDetail($request)
{
    try {
        $sql = "SELECT el.extension, el.status, el.channel, el.campaign_id, el.lead_id, campaign.title
                FROM extension_live as el 
                LEFT JOIN campaign on campaign.id = el.campaign_id";

        $data = DB::connection('mysql_' . $request->auth->parent_id)->select($sql);

        foreach ($data as $key => $live_extension) {
            $user_data = User::where('extension', $live_extension->extension)
                ->orWhere('alt_extension', $live_extension->extension)
                ->first();

            if ($user_data) {
                $data[$key]->full_name = $user_data->first_name . ' ' . $user_data->last_name;
                $data[$key]->extension = $user_data->extension;
            } else {
                $data[$key]->full_name = "";
                $data[$key]->extension = "";
            }
        }

        // Apply pagination if present
        if ($request->has(['start', 'limit'])) {
            $start = (int) $request->input('start');
            $limit = (int) $request->input('limit');
            $data = array_slice($data, $start, $limit, true);
        }

        if (count($data) > 0) {
            return [
                'success' => true,
                'message' => 'Agent Status detail.',
                'data'    => $data
            ];
        }

        return [
            'success' => false,
            'message' => 'Agent not found.',
            'data'    => []
        ];

    } catch (\Exception $e) {
        Log::error($e->getMessage());
        return [
            'success' => false,
            'message' => 'Something went wrong',
            'data'    => []
        ];
    }
}

    public function deleteExt($request)
    {
        try {

            /**
             
                $extension = $request->input('sip');
            $request = "Action: ConfbridgeKick\r\n";
            $request .= "Conference: 37873\r\n";
                $request .= "Action: Logoff\r\n\r\n";


              //  $request .= "Channel: $channel\r\n";
               // $request .= "Timeout: $this->waitTime\r\n";
               // $request .= "Async: yes\r\n";

                // Send originate request
                $param['action'] = 'logout';


             */
            $extension = $request->input('sip');
            $data['extension'] = $extension;
            $sql = "DELETE FROM extension_live where extension= :extension ";
            $record =  DB::connection('mysql_' . $request->auth->parent_id)->select($sql, $data);
            $data = (array)$record;
            if (!empty($data)) {
                return array(
                    'success' => 'true',
                    'message' => 'live extension deleted.'
                );
            }
            return array(
                'success' => 'false',
                'message' => 'live extension not deleted.'
            );
        } catch (Exception $e) {
            Log::log($e->getMessage());
        } catch (InvalidArgumentException $e) {
            Log::log($e->getMessage());
        }
    }
}
