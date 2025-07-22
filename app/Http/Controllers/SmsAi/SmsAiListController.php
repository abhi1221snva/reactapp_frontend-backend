<?php

namespace App\Http\Controllers\SmsAi;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use App\Model\Client\SmsAi\SmsAiList;
use App\Model\Client\SmsAi\SmsAiListData;
use App\Model\Client\SmsAi\SmsAiListHeader;


use App\Model\Client\SmsAi\SmsAiCampaign;

use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;


class SmsAiListController extends Controller
{
    // public function index(Request $request)
    // {
    //      $live_campaigns = SmsAiList::on("mysql_" . $request->auth->parent_id)->join('sms_ai_campaign', 'sms_ai_list.campaign_id', '=', 'sms_ai_campaign.id')->orderBy('sms_ai_list.id','DESC')->get(['sms_ai_list.*','sms_ai_campaign.title as campaign_name'])->all();


    //     return $this->successResponse("SMS AI Lists", $live_campaigns);
    //     //$sms_ai_lists = SmsAiList::on("mysql_" . $request->auth->parent_id)->get()->all();
    // }

    /**
     * @OA\Get(
     *     path="/smsai/lists",
     *     summary="Get SMS AI Lists with Campaign Name and Lead Report Count",
     *     description="Fetches all SMS AI List entries with associated campaign name and count of lead reports.",
     *     tags={"SmsAiList"},
     *     security={{"Bearer":{}}},
     *  *      @OA\Parameter(
     *          name="start",
     *          in="query",
     *          description="Start index for pagination",
     *          required=false,
     *          @OA\Schema(type="integer", default=0)
     *      ),
     *      @OA\Parameter(
     *          name="limit",
     *          in="query",
     *          description="Limit number of records returned",
     *          required=false,
     *          @OA\Schema(type="integer", default=10)
     *      ),
     *     @OA\Response(
     *         response=200,
     *         description="List of SMS AI entries with campaign names and lead report counts",
     *         @OA\JsonContent(
     *             type="array",
     *             @OA\Items(
     *                 @OA\Property(property="id", type="integer", example=101),
     *                 @OA\Property(property="campaign_id", type="integer", example=5),
     *                 @OA\Property(property="campaign_name", type="string", example="My AI Campaign"),
     *                 @OA\Property(property="sms_ai_lead_report_count", type="integer", example=12),
     *                 @OA\Property(property="created_at", type="string", example="2025-04-24T10:00:00Z"),
     *                 @OA\Property(property="updated_at", type="string", example="2025-04-24T10:00:00Z")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Server error",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Failed to retrieve SMS AI Lists.")
     *         )
     *     )
     * )
     */
    public function indexold(Request $request)
    {
        $live_campaigns = SmsAiList::on("mysql_" . $request->auth->parent_id)
            ->join('sms_ai_campaign', 'sms_ai_list.campaign_id', '=', 'sms_ai_campaign.id')
            ->select('sms_ai_list.*', 'sms_ai_campaign.title as campaign_name')
            ->withCount(['smsAiLeadReport'])
            ->orderBy('sms_ai_list.id', 'DESC')
            ->get();

        $live_campaignsArray = $live_campaigns->toArray();
        return $this->successResponse("Sms Ai Lists", $live_campaignsArray);
    }
    public function index(Request $request)
    {
        $query = SmsAiList::on("mysql_" . $request->auth->parent_id)
            ->join('sms_ai_campaign', 'sms_ai_list.campaign_id', '=', 'sms_ai_campaign.id')
            ->select('sms_ai_list.*', 'sms_ai_campaign.title as campaign_name')
            ->withCount(['smsAiLeadReport'])
            ->orderBy('sms_ai_list.id', 'DESC');

        if ($request->has('start') && $request->has('limit')) {
            $start = (int) $request->input('start');
            $limit = (int) $request->input('limit');
            $query->skip($start)->take($limit);
        }

        $live_campaigns = $query->get();

        return $this->successResponse("Sms Ai Lists", $live_campaigns->toArray());
    }


    /**
     * @OA\Put(
     *     path="/smsai/list/add",
     *     summary="Create SMS AI List from Excel file",
     *     description="Uploads and parses an Excel file to create a new SMS AI list with headers and data entries.",
     *     tags={"SmsAiList"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"title", "campaign_id", "file_name"},
     *             @OA\Property(property="title", type="string", example="April Contacts"),
     *             @OA\Property(property="campaign_id", type="integer", example=3),
     *             @OA\Property(property="file_name", type="string", example="contacts_april.xlsx")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Sms Ai List added successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="List added successfully."),
     *             @OA\Property(property="list_id", type="integer", example=123),
     *             @OA\Property(property="campaign_id", type="integer", example=3)
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Invalid request or file missing",
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
            $filePath = env('SMS_AI_LIST_FILE_UPLOAD_PATH') . $request->input('file_name');
        }

        if (!empty($filePath) && file_exists($filePath)) {
            ini_set('max_execution_time', 1800);
            ini_set('memory_limit', '-1');
            $dataBase = 'mysql_' . $request->auth->parent_id;
            $campaignId = $request->input('campaign_id');

            try {
                $reader = Excel::toArray(new Excel(), $filePath);
                if (!empty($reader)) {
                    $list = new SmsAiList();
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


                        DB::connection('mysql_' . $request->auth->parent_id)->table('sms_ai_list_header')->insert($t);
                    }

                    /* return array(
                'success' => 'true',
                'message' => 'List added successfully.',
                't'=>$query_1
               
            );*/
                    foreach (array_chunk($query_1, 2500) as $t1) {


                        DB::connection('mysql_' . $request->auth->parent_id)->table('sms_ai_list_data')->insert($t1);
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
     *     path="/smsai/list/view/{id}",
     *     summary="Get SMS AI List Details",
     *     description="Fetches SMS AI List details including campaign title and list headers by list ID.",
     *     tags={"SmsAiList"},
     *     security={{"Bearer":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID of the SMS AI List",
     *         required=true,
     *         @OA\Schema(type="integer", example=123)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="List detail fetched successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Lists detail."),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="id", type="integer", example=123),
     *                 @OA\Property(property="title", type="string", example="April Contacts"),
     *                 @OA\Property(property="campaign_id", type="integer", example=3),
     *                 @OA\Property(property="file_name", type="string", example="contacts_april.xlsx"),
     *                 @OA\Property(property="campaign_title", type="string", example="April Campaign"),
     *                 @OA\Property(property="list_header", type="array",
     *                     @OA\Items(type="object",
     *                         @OA\Property(property="column_name", type="string", example="option_1"),
     *                         @OA\Property(property="header", type="string", example="Phone Number")
     *                     )
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="List not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="No SMS AI Campaign with id 123")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Failed to fetch list detail",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="Failed to fetch SMS AI Campaign info")
     *         )
     *     )
     * )
     */
    public function show(Request $request, int $id)
    {
        try {


            $live_campaigns = SmsAiList::on("mysql_" . $request->auth->parent_id)->join('sms_ai_campaign', 'sms_ai_list.campaign_id', '=', 'sms_ai_campaign.id')->where('sms_ai_list.id', '=', $id)->get(['sms_ai_list.*', 'sms_ai_campaign.title']);

            $data = $live_campaigns[0];





            $sql = "SELECT * FROM sms_ai_list_header WHERE list_id = :list_id AND is_deleted = :is_deleted";
            $record = DB::connection('mysql_' . $request->auth->parent_id)->select($sql, array('is_deleted' => 0, 'list_id' => $id));
            $listHeader = (array) $record;

            $data['list_header'] = $listHeader;






            return array(
                'success' => 'true',
                'message' => 'Lists detail.',
                'data' => $live_campaigns[0]
            );
        } catch (ModelNotFoundException $exception) {
            throw new NotFoundHttpException("No SMS AI Campaign with id $id");
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to fetch SMS AI Campaign info", [], $exception);
        }
    }


    /**
     * @OA\Post(
     *     path="/smsai/list/update/{id}",
     *     summary="Update SMS AI List",
     *     description="Updates SMS AI List title, campaign, headers, removes null/duplicate values and formats dialing column data.",
     *     tags={"SmsAiList"},
     *     security={{"Bearer":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="ID of the SMS AI List",
     *         @OA\Schema(type="integer", example=123)
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="title", type="string", example="Updated Contact List"),
     *             @OA\Property(property="campaign_id", type="integer", example=4),
     *             @OA\Property(
     *                 property="list_header",
     *                 type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="is_dialing", type="integer", example=1),
     *                     @OA\Property(property="label_id", type="integer", example=5)
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="List updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Lists updated successfully.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="List not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="No SMS AI Campaign with id 123")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Failed to update list",
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
            $list = SmsAiList::on('mysql_' . $request->auth->parent_id)->findOrFail($id);
            $list->title = $request->title;
            $list->campaign_id = $request->campaign_id;
            $list->save();

            if (!empty($request->input('list_header')) && is_array($request->input('list_header'))) {
                foreach ($request->input('list_header') as $item => $value) {
                    if (!empty($value['id']) && is_numeric($value['id'])) {
                        $update['id'] = $value['id'];
                        $update['is_dialing'] = (!empty($value['is_dialing']) && is_numeric($value['is_dialing'])) ? $value['is_dialing'] : 0;
                        $update['label_id'] = (!empty($value['label_id']) && is_numeric($value['label_id'])) ? $value['label_id'] : null;
                        $query = "UPDATE sms_ai_list_header set is_dialing = :is_dialing,  label_id = :label_id WHERE id = :id";
                        $save_3 = DB::connection('mysql_' . $request->auth->parent_id)->update($query, $update);
                    }
                }

                $dialingColumn = SmsAiListHeader::on('mysql_' . $request->auth->parent_id)->where('list_id', $id)->where('is_dialing', 1)->get()->first();
                $dialing_column_name = $dialingColumn->column_name;

                //return ['success' => true,'message' =>$dialing_column_name];


                $list_data = SmsAiListData::on('mysql_' . $request->auth->parent_id)->where('list_id', $id)->pluck($dialing_column_name, 'id')->all();

                //return ['success' => true,'message' =>$list_data];



                foreach ($list_data as $key => $value) {
                    if ($value == null) {
                        $null_data_id[] = $key . $value;
                    }
                }

                //return ['success' => true,'message' =>$null_data_id];


                if (!empty($null_data_id)) {
                    $sql = "delete from sms_ai_list_data where id IN (" . implode(',', $null_data_id) . ")";
                    DB::connection("mysql_" . $request->auth->parent_id)->select($sql);
                }

                //return ['success' => true,'message' =>$null_data_id];

                $sql_update = "UPDATE sms_ai_list_data SET " . $dialing_column_name . " = REPLACE(" . $dialing_column_name . ", '(', ''), " . $dialing_column_name . " = REPLACE(" . $dialing_column_name . ", ')', ''), " . $dialing_column_name . " = REPLACE(" . $dialing_column_name . ", ' ', '') WHERE list_id = id";

                $update['id'] = $id;
                DB::connection('mysql_' . $request->auth->parent_id)->update($sql_update, $update);

                $list_data_after_null_delete = SmsAiListData::on('mysql_' . $request->auth->parent_id)->where('list_id', $id)->pluck($dialing_column_name, 'id')->all();
                $findDuplicate = array_diff_assoc($list_data_after_null_delete,  array_unique($list_data_after_null_delete));
                $array_key  = array_keys($findDuplicate);

                if (!empty($array_key)) {
                    $deleteDublicate = "delete from sms_ai_list_data where id IN (" . implode(',', $array_key) . ")";
                    DB::connection("mysql_" . $request->auth->parent_id)->select($deleteDublicate);
                }
                $final_list_data = SmsAiListData::on('mysql_' . $request->auth->parent_id)->where('list_id', $id)->pluck($dialing_column_name, 'id')->all();
                foreach ($final_list_data as $key => $number) {
                    $mobile = preg_replace('/[^0-9]/', '', $number);
                    $sql_update = "UPDATE sms_ai_list_data SET " . $dialing_column_name . " = '" . $mobile . "' WHERE id = :id";
                    $update_number['id'] = $key;
                    DB::connection('mysql_' . $request->auth->parent_id)->update($sql_update, $update_number);
                }

                $sql_update = "UPDATE sms_ai_list_data SET " . $dialing_column_name . " = RIGHT(" . $dialing_column_name . ", 10)";
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
     *     path="/smsai/list/update-status",
     *     summary="Update SMS AI List",
     *     description="Updates SMS AI List status.",
     *     tags={"SmsAiList"},
     *     security={{"Bearer":{}}},
   
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="listId", type="integer", example="4"),
     *             @OA\Property(property="status", type="string", example=1),
     
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Sms AI List status updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Lists updated successfully.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="List not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="No SMS AI Campaign with id 123")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Failed to update list",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Failed to fetch SMS AI Campaign info")
     *         )
     *     )
     * )
     */

    function updateStatus(Request $request)
    {
        $listId = $request->input('listId');
        $status = $request->input('status');

        $saveRecord = SmsAiList::on('mysql_' . $request->auth->parent_id)->where('id', $listId)->update(array('status' => $status));
        if ($saveRecord > 0) {
            return array(
                'success' => 'true',
                'message' => 'SMS AI List status updated successfully'
            );
        } else {
            return array(
                'success' => 'true',
                'message' => 'SMS Ai List update failed'
            );
        }
    }


    /**
     * @OA\Get(
     *     path="/smsai/list/delete/{id}",
     *     summary="delete SMS AI List Details by ID",
     *     description="delete SMS AI List details  by  ID.",
     *     tags={"SmsAiList"},
     *     security={{"Bearer":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID of the SMS AI List",
     *         required=true,
     *         @OA\Schema(type="integer", example=123)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="List detail deleted successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Lists detail."),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="id", type="integer", example=123),
     *                 @OA\Property(property="title", type="string", example="April Contacts"),
     *                 @OA\Property(property="campaign_id", type="integer", example=3),
     *                 @OA\Property(property="file_name", type="string", example="contacts_april.xlsx"),
     *                 @OA\Property(property="campaign_title", type="string", example="April Campaign"),
     *                 @OA\Property(property="list_header", type="array",
     *                     @OA\Items(type="object",
     *                         @OA\Property(property="column_name", type="string", example="option_1"),
     *                         @OA\Property(property="header", type="string", example="Phone Number")
     *                     )
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="No SMS AI List with id",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="No SMS AI Campaign with id 123")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Failed to fetch list detail with Id",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="Failed to fetch SMS AI Campaign info")
     *         )
     *     )
     * )
     */
    public function delete(Request $request, int $id)
    {
        try {

            $sql = "delete from sms_ai_list_header where list_id='" . $id . "'";
            $records = DB::connection("mysql_" . $request->auth->parent_id)->select($sql);

            $sql = "delete from sms_ai_list_data where list_id='" . $id . "'";
            $records = DB::connection("mysql_" . $request->auth->parent_id)->select($sql);



            $list = SmsAiList::on("mysql_" . $request->auth->parent_id)->findOrFail($id);
            $data = $list->delete();
            return $this->successResponse("SMS AI List info deleted", [$data]);
        } catch (ModelNotFoundException $exception) {
            throw new NotFoundHttpException("No SMS AI List with id $id");
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to fetch SMS AI List info", [], $exception);
        }
    }


    /**
     * @OA\Get(
     *     path="/smsai/list/recycle/{id}",
     *     summary="SMS AI List info recycled by ID",
     *     description="SMS AI List info recycled  by  ID.",
     *     tags={"SmsAiList"},
     *     security={{"Bearer":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID of the SMS AI List",
     *         required=true,
     *         @OA\Schema(type="integer", example=123)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="SMS AI List info recycled  successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Lists detail."),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="id", type="integer", example=123),
     *                 @OA\Property(property="title", type="string", example="April Contacts"),
     *                 @OA\Property(property="campaign_id", type="integer", example=3),
     *                 @OA\Property(property="file_name", type="string", example="contacts_april.xlsx"),
     *                 @OA\Property(property="campaign_title", type="string", example="April Campaign"),
     *                 @OA\Property(property="list_header", type="array",
     *                     @OA\Items(type="object",
     *                         @OA\Property(property="column_name", type="string", example="option_1"),
     *                         @OA\Property(property="header", type="string", example="Phone Number")
     *                     )
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="No SMS AI List with id",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="No SMS AI Campaign with id 123")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Failed to fetch list detail with Id",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="Failed to fetch SMS AI Campaign info")
     *         )
     *     )
     * )
     */
    public function recycle(Request $request, int $id)
    {
        Log::info('reached');
        try {

            $sql = "delete from sms_ai_lead_temp where list_id='" . $id . "'";
            $records = DB::connection("mysql_" . $request->auth->parent_id)->select($sql);

            $sql = "delete from sms_ai_lead_report where list_id='" . $id . "'";
            $records = DB::connection("mysql_" . $request->auth->parent_id)->select($sql);


            /*$list = SmsAiList::on("mysql_" . $request->auth->parent_id)->findOrFail($id);
            $data = $list->delete();*/
            return $this->successResponse("SMS AI List info recycled", [$records]);
        } catch (ModelNotFoundException $exception) {
            throw new NotFoundHttpException("No SMS AI List with id $id");
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to fetch SMS AI List info", [], $exception);
        }
    }
}
