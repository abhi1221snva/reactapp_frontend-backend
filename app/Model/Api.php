<?php

namespace App\Model;

use App\Http\Helper\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Model;

class Api extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $guarded = ['id'];
    protected $table = 'api';
    public $timestamps = false;

    /*
     *Add Api details
     *@param object $request
     *@return array
     */
    public function addApi($request)
    {
        try {
            if (
                $request->has('title') && !empty($request->input('title')) &&
                $request->has('url') && !empty($request->input('url')) &&
                $request->has('campaign_id') && is_numeric($request->input('campaign_id')) &&
                $request->has('method') && !empty($request->input('method')) &&
                $request->has('parameter') && is_array($request->input('parameter'))
            ) {
                $data['title'] = $request->input('title');
                $data['url'] = $request->input('url');
                $data['campaign_id'] = $request->input('campaign_id');
                $data['method'] = $request->input('method');
                $data['is_default'] = $request->input('is_default');

                $query = "INSERT INTO " . $this->table . " (title, url, campaign_id, method, is_default) VALUE (:title, :url, :campaign_id, :method, :is_default)";
                $add =  DB::connection('mysql_' . $request->auth->parent_id)->insert($query, $data);
                $add = 1;
                if ($add == 1) {
                    $apiId = DB::connection('mysql_' . $request->auth->parent_id)->selectOne("SELECT id FROM " . $this->table . " ORDER BY id DESC", array());
                    $apiId = (array) $apiId;
                    $apiId = !empty($apiId) ? $apiId['id'] : '';
                    if (!empty($apiId)) {
                        if (is_array($request->input('parameter'))) {
                            foreach ($request->input('parameter') as $key => $value) {
                                if (!empty($value['type']) && !empty($value['parameter']) && !empty($value['value'])) {
                                    $value['api_id'] = $apiId;
                                    $query = "INSERT IGNORE INTO api_parameter (api_id, type, parameter, value) VALUE (:api_id, :type, :parameter, :value)";
                                    DB::connection('mysql_' . $request->auth->parent_id)->insert(
                                        $query,
                                        $value
                                    );
                                }
                            }
                        }

                        if (is_array($request->input('disposition'))) {
                            foreach ($request->input('disposition') as $key => $item) {
                                if (!empty($item)) {
                                    $query = "INSERT IGNORE INTO api_disposition (api_id, disposition_id) VALUE (:api_id, :disposition_id)";
                                    DB::connection('mysql_' . $request->auth->parent_id)->insert($query, array('disposition_id' => $item, 'api_id' => $apiId));
                                }
                            }
                        }
                    }
                    return array(
                        'success' => 'true',
                        'message' => 'Api added successfully.'
                    );
                } else {
                    return array(
                        'success' => 'false',
                        'message' => 'Api are not added successfully.'
                    );
                }
            }

            return array(
                'success' => 'false',
                'message' => 'Api are not added, Required fields are missing'
            );
        } catch (Exception $e) {
            Log::log($e->getMessage());
        } catch (InvalidArgumentException $e) {
            Log::log($e->getMessage());
        }
    }
    /*
     *Fetch Api list
     *@param integer $id
     *@return array
     */
    public function apiDetail($request)
    {
        if ($request->has('api_id') && is_numeric($request->input('api_id'))) {
            $sql = "SELECT a.*, c.title as campaign FROM " . $this->table . " as a LEFT JOIN campaign as c ON c.id = a.campaign_id WHERE a.is_deleted = :is_deleted AND a.id = :id";
            $record =  DB::connection('mysql_' . $request->auth->parent_id)->selectOne($sql, array('id' => $request->input('api_id'), 'is_deleted' => 0));
            $data = (array)$record;
            $sql = "SELECT type, parameter, value FROM api_parameter  WHERE api_id = :api_id AND is_deleted = :is_deleted";
            $record =  DB::connection('mysql_' . $request->auth->parent_id)->select($sql, array('api_id' => $data['id'], 'is_deleted' => 0));
            $data['parameter'] = (array)$record;
            $sql = "SELECT disposition_id FROM api_disposition  WHERE api_id = :api_id AND is_deleted = :is_deleted";
            $record =  DB::connection('mysql_' . $request->auth->parent_id)->select($sql, array('api_id' => $data['id'], 'is_deleted' => 0));
            $data['disposition'] = (array)$record;
        } else {
            $sql = "SELECT a.*, c.title as campaign FROM " . $this->table . " as a LEFT JOIN campaign as c ON c.id = a.campaign_id  WHERE a.is_deleted = :is_deleted";
            $record =  DB::connection('mysql_' . $request->auth->parent_id)->select($sql, array('is_deleted' => 0));
            $data = (array)$record;
            $totalRows = count($data);
            if ($request->has('start') && $request->has('limit')) {
                $start = (int)$request->input('start'); // Start index (0-based)
                $limit = (int)$request->input('limit'); // Limit number of records to fetch

                // Show all data if start is 0 and limit is provided
                // if ($start == 0 && $limit > 0) {
                //     $data = array_slice($data, 0, $limit); // Fetch only the first 'limit' records
                // } else {
                {
                    // For normal pagination, calculate length from start and limit
                    $length = $limit;
                    $data = array_slice($data, $start, $length, false);
                    // Fetch data from start to start+length
                }

                return response()->json([
                    "success" => true,
                    "message" => "Api detail",
                    'total_rows' => $totalRows,
                    'data'   => $data
                ]);
            }
        }

        if (!empty($data)) {
            return array(
                'success' => 'true',
                'message' => 'Api detail.',
                'data'   => $data
            );
        }
        return array(
            'success' => 'false',
            'message' => 'Api not created.',
            'data'   => array()
        );
    }

    public function apiDetail_old_code($request)
    {
        if ($request->has('api_id') && is_numeric($request->input('api_id'))) {
            $sql = "SELECT a.*, c.title as campaign FROM " . $this->table . " as a LEFT JOIN campaign as c ON c.id = a.campaign_id WHERE a.is_deleted = :is_deleted AND a.id = :id";
            $record =  DB::connection('mysql_' . $request->auth->parent_id)->selectOne($sql, array('id' => $request->input('api_id'), 'is_deleted' => 0));
            $data = (array)$record;
            $sql = "SELECT type, parameter, value FROM api_parameter  WHERE api_id = :api_id AND is_deleted = :is_deleted";
            $record =  DB::connection('mysql_' . $request->auth->parent_id)->select($sql, array('api_id' => $data['id'], 'is_deleted' => 0));
            $data['parameter'] = (array)$record;
            $sql = "SELECT disposition_id FROM api_disposition  WHERE api_id = :api_id AND is_deleted = :is_deleted";
            $record =  DB::connection('mysql_' . $request->auth->parent_id)->select($sql, array('api_id' => $data['id'], 'is_deleted' => 0));
            $data['disposition'] = (array)$record;
        } else {
            $sql = "SELECT a.*, c.title as campaign FROM " . $this->table . " as a LEFT JOIN campaign as c ON c.id = a.campaign_id  WHERE a.is_deleted = :is_deleted";
            $record =  DB::connection('mysql_' . $request->auth->parent_id)->select($sql, array('is_deleted' => 0));
            $data = (array)$record;
        }
        if (!empty($data)) {
            return array(
                'success' => 'true',
                'message' => 'Api detail.',
                'data'   => $data
            );
        }
        return array(
            'success' => 'false',
            'message' => 'Api not created.',
            'data'   => array()
        );
    }

    /*
     *Delete Api
     *@param integer $id
     *@return array
     */
    public function apiDelete($request)
    {
        try {
            if ($request->has('api_id') && is_numeric($request->input('api_id'))) {
                $sql = "UPDATE " . $this->table . " SET is_deleted = :is_deleted WHERE  id = :id";
                $save =  DB::connection('mysql_' . $request->auth->parent_id)->update($sql, array('id' => $request->input('api_id'), 'is_deleted' => 1));
                if ($save == 1) {
                    return array(
                        'success' => 'true',
                        'message' => 'Api Deleted successfully.'
                    );
                } else {
                    return array(
                        'success' => 'false',
                        'message' => 'Unable to delete api.'
                    );
                }
            }
            return array(
                'success' => 'false',
                'message' => 'Unable to delete api, Required information is missing'
            );
        } catch (Exception $e) {
            Log::log($e->getMessage());
        } catch (InvalidArgumentException $e) {
            Log::log($e->getMessage());
        }
    }
    /*
     *Update api details
     *@param object $request
     *@return array
     */
    public function editApi($request)
    {
        try {
            if ($request->has('api_id') && is_numeric($request->input('api_id'))) {
                if ($request->has('parameter') && !empty($request->input('parameter'))) {
                    $apiId = $request->input('api_id');
                    $sql = "DELETE FROM api_parameter WHERE api_id = :api_id";
                    DB::connection('mysql_' . $request->auth->parent_id)->select($sql, array('api_id' => $apiId));
                    foreach ($request->input('parameter') as $key => $value) {
                        if (!empty($value['type']) && !empty($value['parameter']) && !empty($value['value'])) {
                            $value['api_id'] = $apiId;
                            $value['is_deleted'] = 0;
                            $sql = "INSERT INTO api_parameter (api_id, type, parameter, value) VALUE (:api_id, :type, :parameter, :value) ON DUPLICATE KEY UPDATE is_deleted = :is_deleted";
                            DB::connection('mysql_' . $request->auth->parent_id)->insert($sql, $value);
                        }
                    }

                    $save_1 = 1;
                }
                if ($request->has('disposition') && !empty($request->input('disposition'))) {
                    $apiId = $request->input('api_id');
                    $sql = "UPDATE api_disposition SET is_deleted = :is_deleted WHERE api_id = :api_id";
                    DB::connection('mysql_' . $request->auth->parent_id)->update($sql, array('is_deleted' => 1, 'api_id' => $apiId));
                    foreach ($request->input('disposition') as $key => $item) {
                        if (!empty($item)) {
                            $sql = "INSERT INTO api_disposition (api_id, disposition_id) VALUE (:api_id, :disposition_id) ON DUPLICATE KEY UPDATE is_deleted = :is_deleted";
                            DB::connection('mysql_' . $request->auth->parent_id)->insert($sql, array('disposition_id' => $item, 'api_id' => $apiId, 'is_deleted' => 0));
                        }
                    }
                    $save_2 = 1;
                }
                $updateString = array();
                if ($request->has('title') && !empty($request->input('title'))) {
                    array_push($updateString, 'title = :title');
                    $data['title'] = $request->input('title');
                }
                if ($request->has('url') && !empty($request->input('url'))) {
                    array_push($updateString, 'url = :url');
                    $data['url'] = $request->input('url');
                }
                if ($request->has('method') && !empty($request->input('method'))) {
                    array_push($updateString, 'method = :method');
                    $data['method'] = $request->input('method');
                }
                if ($request->has('campaign_id') && is_numeric($request->input('campaign_id'))) {
                    array_push($updateString, 'campaign_id = :campaign_id');
                    $data['campaign_id'] = $request->input('campaign_id');
                }

                if ($request->has('is_default') && is_numeric($request->input('is_default'))) {
                    array_push($updateString, 'is_default = :is_default');
                    $data['is_default'] = $request->input('is_default');
                }
                if (!empty($updateString) && !empty($data)) {
                    $data['id'] = $request->input('api_id');
                    $query = "UPDATE " . $this->table . " set " . implode(" , ", $updateString) . " WHERE id = :id";
                    $save =  DB::connection('mysql_' . $request->auth->parent_id)->update($query, $data);
                    if ($save == 1) {
                        return array(
                            'success' => 'true',
                            'message' => 'Api updated successfully.'
                        );
                    }
                }
            }
            if ($save == 1 || $save_1 == 1 || $save_2 == 1) {
                return array(
                    'success' => 'true',
                    'message' => 'API updated successfully.'
                );
            }
            return array(
                'success' => 'false',
                'message' => 'API doesn\'t exist.'
            );
        } catch (Exception $e) {
            Log::log($e->getMessage());
        } catch (InvalidArgumentException $e) {
            Log::log($e->getMessage());
        }
    }

    public function copyApi($request)
    {
        $api_id = $request->input('api_id');
        $sql = "SELECT * FROM " . $this->table . "  WHERE id = :id";
        $record =  DB::connection('mysql_' . $request->auth->parent_id)->selectOne($sql, array('id' => $api_id));
        $data = (array)$record;
        $dataBase = 'mysql_' . $request->auth->parent_id;
        $recordData = array(
            'title'     => $data['title'],
            'url'       => $data['url'],
            'campaign_id' => $data['campaign_id'],
            'method'    => $data['method'],
            'is_deleted' => $data['is_deleted']
        );
        $insert_id =  DB::connection('mysql_' . $request->auth->parent_id)->table($this->table)->insertGetId($recordData);
        $save_data = true;
        $disposition = "SELECT * FROM api_disposition where api_id= :api_id ";
        $recordDisposition =  DB::connection('mysql_' . $request->auth->parent_id)->select($disposition, array('api_id' => $api_id));
        $dataDisposition = (array)$recordDisposition;
        if (count($dataDisposition) > 0) {
            foreach ($recordDisposition as $key => $val) {
                $h_list['disposition_id']   = $val->disposition_id;
                $h_list['api_id']           = $insert_id;
                $h_list['is_deleted']       = $val->is_deleted;
                $disposition_list[]         = $h_list;
            }
            $save_data &= DB::connection($dataBase)->table('api_disposition')->insert($disposition_list);
        } else {
            $save_data = false;
        }

        $apiParameter = "SELECT * FROM api_parameter where api_id= :api_id ";
        $recordApiParameter =  DB::connection('mysql_' . $request->auth->parent_id)->select($apiParameter, array('api_id' => $api_id));
        $dataApiParameter = (array)$recordApiParameter;
        if (count($dataApiParameter) > 0) {
            foreach ($dataApiParameter as $key1 => $val1) {
                $ap_list['api_id']      = $insert_id;
                $ap_list['type']        = $val1->type;
                $ap_list['parameter']   = $val1->parameter;
                $ap_list['value']       = $val1->value;
                $ap_list['is_deleted']  = $val1->is_deleted;
                $parameter_list[]       = $ap_list;
            }
            $save_data &= DB::connection($dataBase)->table('api_parameter')->insert($parameter_list);
        } else {
            $save_data = false;
        }

        if ($save_data) {
            return array(
                'success' => 'true',
                'message' => 'New API added successfully.',
                'list_id' => $insert_id,
            );
        } else {
            return array(
                'success' => 'false',
                'message' => 'Api not added. Unable to add data in API table'
            );
        }
    }
}
