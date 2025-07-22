<?php

namespace App\Http\Controllers\Ringless;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use App\Model\Client\Ringless\RinglessList;
use App\Model\Client\Ringless\RinglessCampaignList;
use App\Model\Client\Ringless\RinglessListHeader;
use App\Model\Client\Ringless\RinglessListData;

use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;


class RinglessListController extends Controller
{
    // public function index(Request $request)
    // {
    //      $live_campaigns = RinglessList::on("mysql_" . $request->auth->parent_id)->join('ringless_campaign', 'ringless_list.campaign_id', '=', 'ringless_campaign.id')->orderBy('ringless_list.id','DESC')->get(['ringless_list.*','ringless_campaign.title as campaign_name'])->all();


    //     return $this->successResponse("Ringless Lists", $live_campaigns);
    // }
    /**
     * @OA\Get(
     *     path="/ringless/lists",
     *     summary="ringless lists",
     *     tags={"RinglessList"},
     *     security={{"Bearer": {}}},
     *      @OA\Parameter(
     *         name="start",
     *         in="query",
     *         required=false,
     *         description="Start index for pagination",
     *         @OA\Schema(type="integer", default=0)
     *     ),
     *     @OA\Parameter(
     *         name="limit",
     *         in="query",
     *         required=false,
     *         description="Limit number of records returned",
     *         @OA\Schema(type="integer", default=10)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Successful response with ringless list data",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Ringless Lists data."),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="name", type="string", example="Basic Package"),
     *                     @OA\Property(property="price", type="number", format="float", example=19.99),
     *                     @OA\Property(property="duration", type="string", example="30 days")
     *                 )
     *             )
     *         )
     *     )
     * )
     */
    public function index(Request $request)
    {
        $live_campaigns = RinglessList::on("mysql_" . $request->auth->parent_id)
            ->join('ringless_campaign', 'ringless_list.campaign_id', '=', 'ringless_campaign.id')
            ->select('ringless_list.*', 'ringless_campaign.title as campaign_name')
            ->withCount(['ringlessLeadReport'])
            ->orderBy('ringless_list.id', 'DESC')
            ->get();

        $live_campaignsArray = $live_campaigns->toArray();

        $totalRows = count($live_campaignsArray);
        if ($request->has('start') && $request->has('limit')) {
            $start = (int)$request->input('start'); // Start index (0-based)
            $limit = (int)$request->input('limit'); // Limit number of records to fetch

            // Show all data if start is 0 and limit is provided
            if ($start == 0 && $limit > 0) {
                $live_campaignsArray = array_slice($live_campaignsArray, 0, $limit); // Fetch only the first 'limit' records
            } else {
                // For normal pagination, calculate length from start and limit
                $length = $limit;
                $live_campaignsArray = array_slice($live_campaignsArray, $start, $length); // Fetch data from start to start+length
            }

            return response()->json([
                "success" => true,
                "message" => "Ringless Lists",
                'total_rows' => $totalRows,
                "data" => $live_campaignsArray
            ]);
        }

        return $this->successResponse("Ringless Lists", $live_campaignsArray);
    }



    /**
     * @OA\Put(
     *     path="/ringless/list/add",
     *     summary="Upload and process an Excel file for ringless lists",
     *     description="Parses an Excel file and stores data into ringless_list, ringless_list_header, and ringless_list_data tables.",
     *     operationId="createRinglessList",
     *     security={{"Bearer": {}}},
     *     tags={"RinglessList"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"file_name", "campaign_id", "title"},
     *             @OA\Property(property="file_name", type="string", example="contacts.xlsx"),
     *             @OA\Property(property="campaign_id", type="integer", example=123),
     *             @OA\Property(property="title", type="string", example="Spring Campaign List")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="List added successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="List added successfully."),
     *             @OA\Property(property="list_id", type="integer", example=1),
     *             @OA\Property(property="campaign_id", type="integer", example=123)
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Unable to read excel.",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="Unable to read excel.")
     *         )
     *     )
     * )
     */
    public function create(Request $request)
    {
        if ($request->has('file_name')) {
            //$filePath = base_path()."/upload/sms-ai/".$request->input('file_name');
            $filePath = env('RINGLESS_LIST_FILE_UPLOAD_PATH') . $request->input('file_name');
        }

        if (!empty($filePath) && file_exists($filePath)) {
            ini_set('max_execution_time', 1800);
            ini_set('memory_limit', '-1');
            $dataBase = 'mysql_' . $request->auth->parent_id;
            $campaignId = $request->input('campaign_id');

            try {
                $reader = Excel::toArray(new Excel(), $filePath);
                if (!empty($reader)) {
                    $list = new RinglessList();
                    $list->setConnection("mysql_" . $request->auth->parent_id);
                    $list->title = $request->input('title');
                    $list->campaign_id = $request->input('campaign_id');
                    $list->file_name = $request->input('file_name');
                    $list->total_leads = count($reader[0]) - 1;
                    $list->save();

                    $list_id = $list->id;
                    $date_array = array();
                    $header_list = [];
                    ////////////////////////////
                    foreach ($reader as $row) {
                        if (is_array($row)) {
                            foreach ($row as $key => $value) {
                                if ($key == 0) {
                                    $np = 0;
                                    foreach ($value as $em => $ep) {
                                        $ncr = ++$np;
                                        $column_name = 'option_' . $ncr;
                                        if ($ncr > 30) {
                                            continue;
                                        }
                                        //$header_list[] = array('list_id'=>$list_id , 'column_name'=>$column_name , 'header'=>$ep );
                                        $h_list['list_id'] = $list_id;
                                        $h_list['column_name'] = $column_name;
                                        if (empty($ep)) {
                                            $ep = null;
                                        }
                                        $h_list['header'] = $ep;
                                        //  if(empty($column_name) && empty($ep)){ continue; }
                                        $check_date = strlen(strrchr(strtolower($ep), "date"));

                                        if (strpos(strtolower($ep), 'date')) {
                                            $date_array[$ncr] = $ncr;
                                        }
                                        if (!empty($h_list['header'])) {
                                            $header_list[] = $h_list;
                                        }

                                        // $query[] = "INSERT INTO list_header (list_id, column_name, header) VALUE ($list_id, $column_name , $ep)";
                                    }
                                } else {
                                    $var_element[] = 'list_id';
                                    $var_data[] = $list_id;
                                    // $list_data['list_id']=$list_id;
                                    $list_data = array("list_id" => $list_id);
                                    if (empty($value[2]) && empty($value[3]) && empty($value[4])) {
                                        continue;
                                    }
                                    $k = 0;
                                    foreach ($value as $emt => $ept) {
                                        $r = ++$k;
                                        if ($r > 30) {
                                            continue;
                                        }
                                        $var_element[] = 'option_' . $r;
                                        if (!empty($date_array[$r])) {
                                            if (is_int($ept)) {
                                                // +1 day difference added with date
                                                $ept = date("Y-m-d", (($ept - 25569) * 86400));
                                                $ept = date('Y-m-d', strtotime('+1 day', strtotime($ept)));
                                            }
                                        }

                                        $var_data[] = $ept;
                                        $list_data['option_' . $r] = $ept;
                                    }
                                    if (count($list_data) > 0) {
                                        $query_1[] = $list_data;
                                    }
                                    unset($var_data);
                                    unset($var_element);
                                    unset($list_data);
                                }
                                # code...
                            }
                        }
                    }




                    foreach (array_chunk($header_list, 2500) as $t) {


                        DB::connection('mysql_' . $request->auth->parent_id)->table('ringless_list_header')->insert($t);
                    }

                    /* return array(
                'success' => 'true',
                'message' => 'List added successfully.',
                't'=>$query_1
               
            );*/
                    foreach (array_chunk($query_1, 2500) as $t1) {


                        DB::connection('mysql_' . $request->auth->parent_id)->table('ringless_list_data')->insert($t1);
                    }

                    return array(
                        'success' => 'true',
                        'message' => 'List added successfully.',
                        'list_id' => $list_id,
                        'campaign_id' => $request->input('campaign_id')

                    );
                }
            } catch (\Exception $e) {
                return array(
                    'success' => 'false',
                    'message' => 'Unable to read excel.'
                );
            }
        }
    }

    /**
     * @OA\Get(
     *     path="/ringless/list/view/{id}",
     *     summary="ringless lists",
     *     tags={"RinglessList"},
     *     security={{"Bearer": {}}},
     *      @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="ID of the Ringless List",
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Successful response with ringless list data",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Ringless Lists data."),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="name", type="string", example="Basic Package"),
     *                     @OA\Property(property="price", type="number", format="float", example=19.99),
     *                     @OA\Property(property="duration", type="string", example="30 days")
     *                 )
     *             )
     *         )
     *     )
     * )
     */
    public function show(Request $request, int $id)
    {
        try {


            $live_campaigns = RinglessList::on("mysql_" . $request->auth->parent_id)->join('ringless_campaign', 'ringless_list.campaign_id', '=', 'ringless_campaign.id')->where('ringless_list.id', '=', $id)->get(['ringless_list.*', 'ringless_campaign.title']);

            $data = $live_campaigns[0];





            $sql = "SELECT * FROM ringless_list_header WHERE list_id = :list_id AND is_deleted = :is_deleted";
            $record = DB::connection('mysql_' . $request->auth->parent_id)->select($sql, array('is_deleted' => 0, 'list_id' => $id));
            $listHeader = (array) $record;

            $data['list_header'] = $listHeader;






            return array(
                'success' => 'true',
                'message' => 'Lists detail.',
                'data' => $live_campaigns[0]
            );
        } catch (ModelNotFoundException $exception) {
            throw new NotFoundHttpException("No Ringless Campaign with id $id");
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to fetch Ringless Campaign info", [], $exception);
        }
    }

    /**
     * @OA\Post(
     *     path="/ringless/list/update/{id}",
     *     summary="Update a Ringless List",
     *     description="Update the title, campaign_id, and list headers of a ringless list. It also processes list data for duplicates, nulls, and formatting.",
     *     operationId="updateRinglessList",
     *     security={{"Bearer": {}}},
     *     tags={"RinglessList"},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID of the Ringless List to update",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"title", "campaign_id", "list_header"},
     *             @OA\Property(property="title", type="string", example="Updated List Title"),
     *             @OA\Property(property="campaign_id", type="integer", example=101),
     *             @OA\Property(
     *                 property="list_header",
     *                 type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="is_dialing", type="integer", example=1),
     *                     @OA\Property(property="label_id", type="integer", example=5)
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Lists updated successfully.",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Lists updated successfully.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="No SMS AI Campaign with the specified id found.",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="No SMS AI Campaign with id 5")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Failed to fetch or update SMS AI Campaign info.",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Failed to fetch SMS AI Campaign info")
     *         )
     *     )
     * )
     */
    public function update(Request $request, int $id)
    {
        $null_data_id = array();
        try {
            $list = RinglessList::on('mysql_' . $request->auth->parent_id)->findOrFail($id);
            $list->title = $request->title;
            $list->campaign_id = $request->campaign_id;
            $list->save();

            if (!empty($request->input('list_header')) && is_array($request->input('list_header'))) {
                foreach ($request->input('list_header') as $item => $value) {
                    if (!empty($value['id']) && is_numeric($value['id'])) {
                        $update['id'] = $value['id'];
                        $update['is_dialing'] = (!empty($value['is_dialing']) && is_numeric($value['is_dialing'])) ? $value['is_dialing'] : 0;
                        $update['label_id'] = (!empty($value['label_id']) && is_numeric($value['label_id'])) ? $value['label_id'] : null;
                        $query = "UPDATE ringless_list_header set is_dialling = :is_dialing,  label_id = :label_id WHERE id = :id";
                        $save_3 = DB::connection('mysql_' . $request->auth->parent_id)->update($query, $update);
                    }
                }

                $dialingColumn = RinglessListHeader::on('mysql_' . $request->auth->parent_id)->where('list_id', $id)->where('is_dialling', 1)->get()->first();
                $dialing_column_name = $dialingColumn->column_name;

                //return ['success' => true,'message' =>$dialing_column_name];


                $list_data = RinglessListData::on('mysql_' . $request->auth->parent_id)->where('list_id', $id)->pluck($dialing_column_name, 'id')->all();

                //return ['success' => true,'message' =>$list_data];



                foreach ($list_data as $key => $value) {
                    if ($value == null) {
                        $null_data_id[] = $key . $value;
                    }
                }

                //return ['success' => true,'message' =>$null_data_id];


                if (!empty($null_data_id)) {
                    $sql = "delete from ringless_list_data where id IN (" . implode(',', $null_data_id) . ")";
                    DB::connection("mysql_" . $request->auth->parent_id)->select($sql);
                }

                //return ['success' => true,'message' =>$null_data_id];

                $sql_update = "UPDATE ringless_list_data SET " . $dialing_column_name . " = REPLACE(" . $dialing_column_name . ", '(', ''), " . $dialing_column_name . " = REPLACE(" . $dialing_column_name . ", ')', ''), " . $dialing_column_name . " = REPLACE(" . $dialing_column_name . ", ' ', '') WHERE list_id = id";

                $update['id'] = $id;
                DB::connection('mysql_' . $request->auth->parent_id)->update($sql_update, $update);

                $list_data_after_null_delete = RinglessListData::on('mysql_' . $request->auth->parent_id)->where('list_id', $id)->pluck($dialing_column_name, 'id')->all();

                //return ['success' => true,'message' =>$list_data_after_null_delete];

                $findDuplicate = array_diff_assoc($list_data_after_null_delete,  array_unique($list_data_after_null_delete));
                $array_key  = array_keys($findDuplicate);

                if (!empty($array_key)) {
                    $deleteDublicate = "delete from ringless_list_data where id IN (" . implode(',', $array_key) . ")";
                    DB::connection("mysql_" . $request->auth->parent_id)->select($deleteDublicate);
                }
                $final_list_data = RinglessListData::on('mysql_' . $request->auth->parent_id)->where('list_id', $id)->pluck($dialing_column_name, 'id')->all();
                foreach ($final_list_data as $key => $number) {
                    $mobile = preg_replace('/[^0-9]/', '', $number);
                    $sql_update = "UPDATE ringless_list_data SET " . $dialing_column_name . " = '" . $mobile . "' WHERE id = :id";
                    $update_number['id'] = $key;
                    DB::connection('mysql_' . $request->auth->parent_id)->update($sql_update, $update_number);
                }

                $sql_update = "UPDATE ringless_list_data SET " . $dialing_column_name . " = RIGHT(" . $dialing_column_name . ", 10)";
                $update_number['id'] = $id;
                DB::connection('mysql_' . $request->auth->parent_id)->update($sql_update);

                $list->total_leads = count($final_list_data);
                $list->is_dialing = 1;

                $list->save();
            }
            return [
                'success' => true,
                'message' => 'Lists updated successfully.'
            ];
        } catch (ModelNotFoundException $exception) {
            throw new NotFoundHttpException("No SMS AI Campaign with id $id");
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to fetch SMS AI Campaign info", [], $exception);
        }
    }


    /**
     * @OA\Post(
     *     path="/ringless/list/update-status",
     *     summary="update a specific ringless list status using its ID.",
     *     tags={"RinglessList"},
     *     security={{"Bearer": {}}},
     * *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"listId,status"},
     *             @OA\Property(property="listId", type="integer", example=42),
     *             @OA\Property(
     *             property="status",
     *             type="string",
     *             enum={"1", "0"},
     *             example="1",
     *            description="Campaign status: '1' for active, '0' for inactive")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="update a specific ringless list status using its ID",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="List of ringless List  ."),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="name", type="string", example="Basic Package"),
     *                     @OA\Property(property="price", type="number", format="float", example=19.99),
     *                     @OA\Property(property="duration", type="string", example="30 days")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Campaign not found"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error"
     *     )
     * )
     */
    function updateStatus(Request $request)
    {
        $listId = $request->input('listId');
        $status = $request->input('status');

        $saveRecord = RinglessList::on('mysql_' . $request->auth->parent_id)->where('id', $listId)->update(array('status' => $status));
        if ($saveRecord > 0) {
            return array(
                'success' => 'true',
                'message' => 'Ringless List status updated successfully'
            );
        } else {
            return array(
                'success' => 'true',
                'message' => 'Ringless List update failed'
            );
        }
    }


    /**
     * @OA\get(
     *     path="/ringless/list/delete/{id}",
     *     summary="Delete a Ringless List",
     *     description="Deletes a Ringless List record by its ID from the ringless_list_header and ringless_list_data tables.",
     *     operationId="deleteRinglessList",
     *     tags={"RinglessList"},
     *     security={{"Bearer":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID of the Ringless List to delete",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Ringless List info deleted",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Ringless List info deleted"),
     *             @OA\Property(property="data", type="array", @OA\Items(type="object"))
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="No Ringless List with given id",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="No Ringless List with id 1")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Failed to fetch Ringless List info",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Failed to fetch Ringless List info")
     *         )
     *     )
     * )
     */
    public function delete(Request $request, int $id)
    {
        try {

            $sql = "delete from ringless_list_header where list_id='" . $id . "'";
            $records = DB::connection("mysql_" . $request->auth->parent_id)->select($sql);

            $sql = "delete from ringless_list_data where list_id='" . $id . "'";
            $records = DB::connection("mysql_" . $request->auth->parent_id)->select($sql);



            $list = RinglessList::on("mysql_" . $request->auth->parent_id)->findOrFail($id);
            $data = $list->delete();
            return $this->successResponse("Ringless List info deleted", [$data]);
        } catch (ModelNotFoundException $exception) {
            throw new NotFoundHttpException("No Ringless List with id $id");
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to fetch Ringless List info", [], $exception);
        }
    }

    /**
     * @OA\get(
     *     path="/ringless/list/recycle/{id}",
     *     summary="Ringless List info recycled",
     *     description="Deletes a Ringless List record by its ID from the ringless_list_header and ringless_list_data tables.",
     *     operationId="deleteRinglessList",
     *     tags={"RinglessList"},
     *     security={{"Bearer":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID of the Ringless List to delete",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Ringless List info deleted",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Ringless List info deleted"),
     *             @OA\Property(property="data", type="array", @OA\Items(type="object"))
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="No Ringless List with given id",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="No Ringless List with id 1")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Failed to fetch Ringless List info",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Failed to fetch Ringless List info")
     *         )
     *     )
     * )
     */
    public function recycle(Request $request, int $id)
    {
        try {

            $sql = "delete from ringless_lead_temp where list_id='" . $id . "'";
            $records = DB::connection("mysql_" . $request->auth->parent_id)->select($sql);

            $sql = "delete from ringless_lead_report where list_id='" . $id . "'";
            $records = DB::connection("mysql_" . $request->auth->parent_id)->select($sql);



            /*$list = SmsAiList::on("mysql_" . $request->auth->parent_id)->findOrFail($id);
            $data = $list->delete();*/
            return $this->successResponse("Ringless List info recycled", [$records]);
        } catch (ModelNotFoundException $exception) {
            throw new NotFoundHttpException("No Ringless List with id $id");
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to fetch Ringless AI List info", [], $exception);
        }
    }
}
