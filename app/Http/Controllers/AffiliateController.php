<?php

namespace App\Http\Controllers;

use App\Model\Client\CrmLabel;
use App\Model\Client\Lead;
use App\Model\Client\Lists;
use App\Model\Client\ExtensionGroupMap;
use App\Model\Master\DomainList;

use App\Model\Client\Notification;
use App\Model\Client\Documents;


use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use App\Model\Role;
use App\Model\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Maatwebsite\Excel\Facades\Excel;
use App\Jobs\SendCrmNotificationEmail;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\NotificationController;


use File;

/**
 * @OA\Get(
 *   path="/check-affiliate-link/{client_id}/{extension_id}/{token_url}",
 *   summary="Check affiliate link validity",
 *   operationId="affiliateCheckLink",
 *   tags={"Affiliate"},
 *   @OA\Parameter(name="client_id", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Parameter(name="extension_id", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Parameter(name="token_url", in="path", required=true, @OA\Schema(type="string")),
 *   @OA\Response(response=200, description="Affiliate link status"),
 *   @OA\Response(response=401, description="Unauthenticated")
 * )
 *
 * @OA\Put(
 *   path="/affiliate/lead/add/{client_id}/{extension_id}",
 *   summary="Create a lead via affiliate link",
 *   operationId="affiliateCreateLead",
 *   tags={"Affiliate"},
 *   @OA\Parameter(name="client_id", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Parameter(name="extension_id", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Response(response=200, description="Lead created")
 * )
 *
 * @OA\Put(
 *   path="/save-document-affiliate/{clientId}",
 *   summary="Save document via affiliate",
 *   operationId="affiliateCreateDocument",
 *   tags={"Affiliate"},
 *   @OA\Parameter(name="clientId", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Response(response=200, description="Document saved")
 * )
 *
 * @OA\Put(
 *   path="/add-notification-affiliate/add/{leadId}/{clientId}",
 *   summary="Add notification via affiliate",
 *   operationId="affiliateCreateNotification",
 *   tags={"Affiliate"},
 *   @OA\Parameter(name="leadId", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Parameter(name="clientId", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Response(response=200, description="Notification created")
 * )
 */
class AffiliateController extends Controller
{
    public function checkAffiliateLink(Request $request,$client_id,$extension_id,$token_url)
    {
        $client_id = ($client_id);
        $extension_id = ($extension_id);

        $final_affiliate_link = '/'.$client_id.'/'.$extension_id.'/'.$token_url;

        $user_affiliate_link = User::where('affiliate_link',$final_affiliate_link)->get()->first();
        return array(
            'success' => 'true',
            'message' => 'Affiliate Link Status',
            'data' => $user_affiliate_link
        );
    }
    public function list(Request $request)
    {
        ini_set('max_execution_time', 1800);
        try
        {
            $search = array();
            $searchString = array();
            $searchString1 = array();
            $limitString = '';

            $clientId = $request->auth->parent_id;
            $leads = [];
            $level = $request->auth->user_level;

            if ($request->has('lead_status') && !empty($request->input('lead_status')))
                {
                    $searchString1 = $request->input('lead_status');
                    $result = "'" . implode ( "', '", $searchString1 ) . "'";
                    array_push($searchString, " (lead_status IN ($result))");
                }

                if ($request->has('assigned_to') && !empty($request->input('assigned_to')))
                {
                    $searchString1 = $request->input('assigned_to');
                    $result = "'" . implode ( "', '", $searchString1 ) . "'";
                    array_push($searchString, " (assigned_to IN ($result))");
                }

                if ($request->has('lead_type') && !empty($request->input('lead_type')))
                {
                    $searchString1 = $request->input('lead_type');
                    $result = "'" . implode ( "', '", $searchString1 ) . "'";
                    array_push($searchString, " (lead_type IN ($result))");
                }

                if ($request->has('first_name') && !empty($request->input('first_name')))
                {
                    $search['first_name'] = $request->input('first_name');
                    array_push($searchString, "first_name like CONCAT('%',:first_name)");
                }

                if ($request->has('last_name') && !empty($request->input('last_name')))
                {
                    $search['last_name'] = $request->input('last_name');
                    array_push($searchString, "last_name like CONCAT('%',:last_name)");
                }

                if ($request->has('crm_id') && !empty($request->input('crm_id')))
                {
                    $search['id'] = $request->input('crm_id');
                     array_push($searchString, 'id = :id');
                }

                if ($request->has('phone_number') && !empty($request->input('phone_number')))
                {
                    $search['phone_number'] = $request->input('phone_number');
                     array_push($searchString, "phone_number like CONCAT(:phone_number, '%')");

                }

                if ($request->has('email') && !empty($request->input('email')))
                {
                    $search['email'] = $request->input('email');
                    array_push($searchString, "email like CONCAT('%',:email, '%')");
                }

                if ($request->has('company_name') && !empty($request->input('company_name')))
                {

                    $search['company_name'] = $request->input('company_name');
                    array_push($searchString, "company_name like CONCAT('%',:company_name, '%')");
                }

                if ($request->has('start_date') && $request->has('end_date') && !empty($request->input('start_date')) && !empty($request->input('end_date')))
                {
                    $start = date('Y-m-d', strtotime($request->input('start_date'))) . " 00:00:00";
                    $end = date('Y-m-d', strtotime($request->input('end_date'))) . " 23:59:59";
                    $search['start_time'] = $start;
                    $search['end_time'] = $end;
                    array_push($searchString, 'created_at BETWEEN :start_time AND :end_time');
                }

                if ($request->has('lower_limit') && $request->has('upper_limit') && is_numeric($request->input('lower_limit')) && is_numeric($request->input('upper_limit')))
                {
                    $search['lower_limit'] = $request->input('lower_limit');
                    $search['upper_limit'] = $request->input('upper_limit');
                    $limitString = "LIMIT :lower_limit , :upper_limit";
                }

            
            if($level > 1)
            {
                
                $filter = (!empty($searchString)) ? " WHERE " . implode(" AND ", $searchString) : '';
                $query_string = "Select * from crm_lead_data as crm $filter order by created_at desc ";
                $sql = $query_string . $limitString;

               /*  return array(
                    'success' => 'true',
                    'message' => 'Call Data Report.',
                    'record_count' =>0,
                    'data' => $filter
                );*/

                $record = DB::connection('mysql_' . $request->auth->parent_id)->select($sql, $search);
                $recordCount = DB::connection('mysql_' . $request->auth->parent_id)->selectOne("SELECT COUNT(*) as count FROM crm_lead_data $filter", $search);
                $recordCount = (array) $recordCount;

                if (!empty($record))
                {
                    $data = (array) $record;
                    return array(
                    'success' => 'true',
                    'message' => 'Call Data Report.',
                    'record_count' => $recordCount['count'],
                    'data' => $data
                );
                } 
                else
                {
                    return array(
                        'success' => 'true',
                        'message' => 'No Call Data Report found.',
                        'record_count' => 0,
                        'data' => array()
                    );
                }
            }

            else
            {

                
              //  $leads = Lead::on("mysql_$clientId")->where('assigned_to',$request->auth->id)->orderBy('id','desc')->get()->all();

                if ($request->auth->id)
                {
                    $search['assigned_to'] = $request->auth->id;
                     array_push($searchString, 'assigned_to = :assigned_to');
                }

                $filter = (!empty($searchString)) ? " WHERE " . implode(" AND ", $searchString) : '';

                $query_string = "Select * from crm_lead_data as crm $filter order by created_at desc ";
                $sql = $query_string . $limitString;

                $record = DB::connection('mysql_' . $request->auth->parent_id)->select($sql, $search);
                $recordCount = DB::connection('mysql_' . $request->auth->parent_id)->selectOne("SELECT COUNT(*) as count FROM crm_lead_data $filter", $search);
                $recordCount = (array) $recordCount;

                if (!empty($record))
                {
                    $data = (array) $record;
                    return array(
                    'success' => 'true',
                    'message' => 'Call Data Report.',
                    'record_count' => $recordCount['count'],
                    'data' => $data
                );
                } 
                else
                {
                    return array(
                        'success' => 'true',
                        'message' => 'No Call Data Report found.',
                        'record_count' => 0,
                        'data' => array()
                    );
                }
            }

            return $this->successResponse("List of Lead data", $leads);
        }
        catch (\Throwable $exception)
        {
            return $this->failResponse("Failed to Lead Data ", [$exception->getMessage()], $exception, $exception->getCode());
        }
    }

    public function import(Request $request)
    {
        try
        {

            $domain_list = $this->getPortalBaseUrl((int) $request->auth->parent_id);

            $file_path = env('FILE_UPLOAD_PATH');
            $title = $request->title;
            $reader = Excel::toArray(new Excel(), $file_path . "".$request->file."");

            //add list title
            $list = new Lists();
            $list->setConnection("mysql_".$request->auth->parent_id);
            $list->title = $title;
            $list->saveOrFail();

            $lastId = $list->id;

            if (!empty($reader))
            {
                $date_array = array();
                $header_list = [];

                foreach ($reader as $row)
                {
                    if (is_array($row))
                    {
                        foreach ($row as $key => $value)
                        {
                            if ($key == 0)
                            {
                                $np = 100;
                                foreach ($value as $em => $ep)
                                {
                                    $h_list['list_id'] = $lastId;

                                    $ncr = ++$np;
                                    $column_name = 'option_' . $ncr;
                                    if ($ncr > 131)
                                    {
                                        continue;
                                    }
                                    $h_list['column_name'] = $column_name;
                                    if (empty($ep))
                                    {
                                        $ep = null;
                                    }

                                    $h_list['header'] = $ep;
                                    $check_date = strlen(strrchr(strtolower($ep), "date"));
                                    if (strpos(strtolower($ep), 'date'))
                                    {
                                        $date_array[$ncr] = $ncr;
                                    }
                                    if (!empty($h_list['header']))
                                    {
                                        $header_list[] = $h_list;
                                    }
                                }
                            } 
                            else
                            {

                                $k = 100;
                                foreach ($value as $emt => $ept)
                                {
                                    $r = ++$k;
                                    if ($r > 131)
                                    {
                                        continue;
                                    }
                                    $list_data['list_id'] = $lastId;
                                    $list_data['assigned_to'] = $request->auth->id;
                                    $list_data['created_at'] = date('y-m-d h:i:s');
                                    $list_data['updated_at'] = date('y-m-d h:i:s');
                                    $list_data['unique_token'] = $this->generateCode();
                                    $url = $domain_list . '/merchant/customer/app/index/' . $request->auth->parent_id . '/' . $r . '/' . $list_data['unique_token'];
                                    $list_data['unique_url'] = '<a href="' . $url . '">Click Here</a>';

                                    $list_data['lead_status'] = 'new_lead';
                                    $list_data['option_' . $r] = $ept;
                                    $var_element[] = 'option_' . $r;
                                    if (!empty($date_array[$r]))
                                    {
                                        if (is_int($ept))
                                        {
                                            // +1 day difference added with date
                                            $ept = date("Y-m-d", (($ept - 25569) * 86400));
                                            $ept = date('Y-m-d', strtotime('+1 day', strtotime($ept)));
                                        }
                                    }

                                    $var_data[] = $ept;
                                }

                                if (count($list_data) > 0)
                                {
                                    $query_1[] = $list_data;
                                }

                                unset($var_data);
                                unset($var_element);
                                unset($list_data);
                            }
                        }
                    }
                }
            }

            //return $query_1;
            //return $header_list;


            if (count($query_1) > 0)
            {
                $save_data = true;

                foreach (array_chunk($header_list, 1000) as $t)
                {
                    $save_data &= DB::connection("mysql_".$request->auth->parent_id)->table('crm_list_header')->insert($t);
                }

                foreach (array_chunk($query_1, 1000) as $t1)
                {
                    $save_data &= DB::connection("mysql_".$request->auth->parent_id)->table('crm_lead_data')->insert($t1);
                }

                $data = [
                    "action" => "List added",
                    "listId" => $lastId,
                    "listName" => $request->input('title'),
                    "records" => count($query_1),
                    "columns" => $header_list
                ];

                return array(
                    'success' => 'true',
                    'message' => 'List added successfully.',
                    'list_id' => $lastId,
                );
            }
        }
        catch (\Throwable $exception)
        {
            return $this->failResponse("Failed to Upload Lead Data ", [$exception->getMessage()], $exception, $exception->getCode());
        }
    }

    public function create(Request $request,$client_id,$extension_id)
    {
        $this->validate($request, ['phone_number' => 'nullable|sometimes|max:255|unique:'.'mysql_'.$client_id.'.crm_lead_data','email' => 'nullable|sometimes|unique:'.'mysql_'.$client_id.'.crm_lead_data']);

Log::info('reached',[$request->all()]);
//return $request->all();

            $phone_new = str_replace(array('(',')', '_', '-',' '), array(''), $request->phone_number);

            $request['phone_number'] = $phone_new;

        //return $request->all();
             $clientId = $client_id;
        /*$this->validate($request, ['phone_number' => 'required|string|max:255|unique:'.'mysql_'.$clientId.'.crm_lead_data','email' => 'required|string|unique:'.'mysql_'.$clientId.'.crm_lead_data']);*/

        $domain_list = $this->getPortalBaseUrl((int) $clientId);

        $clientId = $clientId;

        //Validation
        $arrValidationRules = $this->validateLeadInfo($clientId);
        $this->validate($request, $arrValidationRules);

        try {

            $user = User::where('extension',$extension_id)->get()->first();



            $objLead = new Lead($request->all());

             $checkExsitLead = Lead::on('mysql_'.$clientId)->where('email',$request->email)->orWhere('phone_number',$request->phone_number)->orderBy('id','ASC')->get()->first();
            if(!empty($checkExsitLead))
            {
                 $objLead->lead_parent_id = $checkExsitLead->id;
            }


            if(isset($objLead->dob))
                $objLead->dob = \Carbon\Carbon::parse($objLead->dob)->format('Y-m-d');
                if ($extension_id) {
                    $extensionGroups = ExtensionGroupMap::on("mysql_$clientId")
                        ->where('extension', $extension_id)
                        ->where('is_deleted', 0)
                        ->pluck('group_id');
                
                    Log::info('extension group checked', ['extensionGroups' => $extensionGroups]);
                
                    if ($extensionGroups->isNotEmpty()) {
                        // Convert all group_ids to an array
                        $group_ids = $extensionGroups->map(function ($id) {
                            return (string)$id; // Ensure IDs are strings
                        })->toArray(); // Convert to array
                
                        Log::info('extension group id checked', ['group_ids' => $group_ids]);
                
                        // Add group_ids to lead as JSON string if necessary
                        $objLead->group_id = json_encode($group_ids); // Store as JSON string for database
                    } else {
                        Log::warning("No group_id found for extension: {$extension_id}");
                    }
                }
            $objLead->setConnection("mysql_$clientId");
            $objLead->lead_status ='new_lead';
            $objLead->saveOrFail();

            $lastId = $objLead->id;
            $phone = $objLead->phone_number;
            $phone_new = str_replace(array('(',')', '_', '-',' '), array(''), $phone);
            $unique_token = $this->generateCode();
            $merchant_url = $domain_list . '/merchant/customer/app/index/' . $clientId . '/' . $lastId . '/' . $unique_token;
            $url = '<a href="' . $merchant_url . '">Click Here</a>';
            $lead = Lead::on("mysql_$clientId")->findorfail($lastId);
            $lead->unique_url = $url;
            $lead->unique_token = $unique_token;
            $lead->phone_number = $phone_new;
            $lead->created_by = $user->id;
            $lead->assigned_to = $user->id;
            $lead->signature_image = $request->signature_image;
            $lead->owner_2_signature_image = $request->owner_2_signature_image;
            $lead->owner_2_signature_date =  Carbon::now();
            $closer_id_value = '["' . $user->id. '"]';
            $lead->closer_id = $closer_id_value;

            $lead->lead_source_id = '348624'; // lead source id for affilaite link title


            $lead->save();

            // Save EAV dynamic fields
            $this->saveEavFields($client_id, (int)$lastId, $request->all());

            $clientId = $client_id;
            $Notification = new Notification();
            $Notification->setConnection("mysql_$clientId");
            $Notification->user_id = $user->id;
            $Notification->lead_id = $lead->id;
            $Notification->message = 'created lead by Affiliate Link'; //$request->message;
            if ($request->has("type"))
                $Notification->type = $request->input("type");
            else
            $Notification->type = '0';
            $Notification->saveOrFail();
            
            $messageData = array(
                "lead_id" => $lead->id,
                "message" => 'created lead by Affiliate Link',
                "user_id" => $user->id,
                'type' => $request->input("type"),
                'mailable' =>"emails.crm-generic"

            );
            $notificationData = [
                "action" => "notification",
                "user" => $messageData
            ];


            //dispatch(new SendCrmNotificationEmail($clientId, $notificationData, 'notification'))->onConnection("database");

            $notificationController = new NotificationController();
            $notificationController->sendCrmNotification($clientId, $notificationData, 'notification');
            
            
            if(!empty($request->signature_image))
            {
                
                $Documents = Documents::on("mysql_$clientId")->where('document_type','signature_application')->where('lead_id', $lead->id)->get()->first();
                
                
                if(empty($Documents))
                {
                    
                    $filename = 'signed_application_'.time().'.pdf';
                    $Documents = new Documents();
                    $Documents->setConnection("mysql_$clientId");
                    $Documents->lead_id =  $lead->id;
                    $Documents->document_name = 'Signed Application';
                    $Documents->document_type = 'signature_application';
                    $Documents->file_name = $filename;
                    $Documents->file_size = '5KB';
                    
                    $Documents->saveOrFail();
                    
                    $objLead['doc_file_name'] = $filename;
                }
                
            }

          

            return $this->successResponse("Lead Added Successfully", $objLead->toArray());
        } catch (\Exception $exception) {
            return $this->failResponse("Failed to create Lead ", [
                $exception->getMessage()
            ], $exception, 500);
        }
    }

    public function update(Request $request, $id)
    {



        $clientId = $request->auth->parent_id;

        //Validation
        $arrValidationRules = $this->validateLeadInfo($clientId);
        $this->validate($request, $arrValidationRules);

        try {
            $objLead = Lead::on("mysql_$clientId")->findOrFail($id);


            $arrFormatLeadInfo = $this->formatLeadInfo($request->all(), $clientId);
            foreach ($arrFormatLeadInfo as $strLeadLabel => $strLeadValue) {
                //if ($objLead->$strLeadLabel != $strLeadValue)
                    $objLead->$strLeadLabel = $strLeadValue;
            }

            $oldlead_status = $objLead->getOriginal('lead_status');
            $objLead->saveOrFail();
            $this->saveEavFields($clientId, (int)$id, $request->all());
            $objLead['old_lead_status'] =  $oldlead_status ;
            return $this->successResponse("Lead Updated Successfully", $objLead->toArray());
        } catch (ModelNotFoundException $exception) {
            return $this->failResponse("Lead Not Found", [
                "Invalid Lead id: $id"
            ], $exception, 404);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to update Lead", [
                $exception->getMessage()
            ], $exception, 404);
        }
    }



    public function updateLeadStatus(Request $request, $id)
    {



        $clientId = $request->auth->parent_id;

       

        try {
            $objLead = Lead::on("mysql_$clientId")->findOrFail($id);
            $objLead->lead_status = $request->lead_status;
            $objLead->lead_type = $request->lead_type;
            $objLead->assigned_to = $request->assigned_to;

            $user = User::findOrFail($request->assigned_to);

            $user_new = $user->first_name.' '.$user->last_name;




            $oldlead_status = $objLead->getOriginal('lead_status');
            $oldlead_type = $objLead->getOriginal('lead_type');
            $oldassigned_to = $objLead->getOriginal('assigned_to');

            $user_old = User::findOrFail($oldassigned_to);

            $user_old = $user_old->first_name.' '.$user_old->last_name;


            $objLead->saveOrFail();
            $objLead->assigned_to = $user_new;

            $objLead['old_lead_status'] =  $oldlead_status ;
            $objLead['old_lead_type']   =  $oldlead_type ;
            $objLead['old_assigned_to']   =  $user_old ;


            return $this->successResponse("Lead Updated Successfully", $objLead->toArray());
        } catch (ModelNotFoundException $exception) {
            return $this->failResponse("Lead Not Found", [
                "Invalid Lead id: $id"
            ], $exception, 404);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to update Lead", [
                $exception->getMessage()
            ], $exception, 404);
        }
    }

    public function delete(Request $request, $id)
    {
        $clientId = $request->auth->parent_id;
        try {

             $sql = "delete from crm_notifications where lead_id='".$id."'";
             $records = DB::connection("mysql_$clientId")->select($sql);
            $objLead = Lead::on("mysql_$clientId")->findOrFail($id);
            $objLead->delete();

            return $this->successResponse("Lead Deleted Successfully", [$objLead]);
        } catch (ModelNotFoundException $exception) {
            throw new NotFoundHttpException("No Lead with id $id");
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to fetch Lead info", [], $exception);
        }
    }

    public function show(Request $request, int $id)
    {
        $clientId = $request->auth->parent_id;
        try {
            $arrLead = Lead::on("mysql_$clientId")->findorfail($id)->toArray();
            return $this->successResponse("Lead info", $arrLead);
        } catch (ModelNotFoundException $exception) {
            throw new NotFoundHttpException("No Lead with id $id");
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to fetch lead info", [$exception->getMessage()], $exception);
        }
    }


    public function showByToken(Request $request, $id, $clientId)
    {
        return 1;
       return  $clientId = $clientId;
        try {
            $arrLead = Lead::on("mysql_$clientId")->where('unique_token',$id)->toArray();
            return $this->successResponse("Lead info", $arrLead);
        } catch (ModelNotFoundException $exception) {
            throw new NotFoundHttpException("No Lead with id $id");
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to fetch lead info", [$exception->getMessage()], $exception);
        }
    }



    public function validateLeadInfo($clientId)
    {
        $arrLabels = CrmLabel::on("mysql_$clientId")->where('status','1')->get()->toArray();

        return $arrLabels;

        foreach ($arrLabels as $key => $label) {
            $strRule = '';

            if ($label['required'])
                $strRule = $strRule . 'required';
            else
                $strRule = $strRule . 'sometimes';

            if ($label['data_type'] == 'date') {
                if (!empty($strRule)) $strRule = $strRule . '|';
                $strRule = $strRule . 'date';
            } elseif (($label['data_type'] == 'text' && $label['title'] == 'email') || ($label['data_type'] == 'text' && $label['title'] == 'Email')) {
                if (!empty($strRule)) $strRule = $strRule . '|';
                $strRule = $strRule . 'email';
            } elseif ($label['data_type'] == 'phone_number') {
                if (!empty($strRule)) $strRule = $strRule . '|';
                $strRule = $strRule . 'regex:/\([0-9]{3}\) [0-9]{3}-[0-9]{4}/';
            } elseif ($label['data_type'] == 'text' || $label['data_type'] == 'select_option') {
                if (!empty($strRule)) $strRule = $strRule . '|';
                $strRule = $strRule . 'string|max:255';
            } elseif ($label['data_type'] == 'date') {
                if (!empty($strRule)) $strRule = $strRule . '|';
                $strRule = $strRule . 'date';
            }
            $arrValidationRules[$label['column_name']] = $strRule;
        }
        return $arrValidationRules;
    }

    public function formatLeadInfo($arrInputLeadInfo, $clientId){
        // Only process column-backed labels; EAV labels are saved via saveEavFields()
        $arrLabels = CrmLabel::on("mysql_$clientId")
            ->where('label_title_url', '!=', "unique_url")
            ->where('status', 1)
            ->where('storage_type', 'column')
            ->get()->toArray();

        foreach ($arrLabels as $arrLabel) {
            $arrInputLeadInfo[$arrLabel['column_name']] = (!empty($arrInputLeadInfo[$arrLabel['column_name']])) ? trim($arrInputLeadInfo[$arrLabel['column_name']]) : '';
        }
        return $arrInputLeadInfo;
    }

    private function saveEavFields(string $clientId, int $leadId, array $input): void
    {
        try {
            $eavLabels = \Illuminate\Support\Facades\DB::connection("mysql_$clientId")
                ->table('crm_label')
                ->where('storage_type', 'eav')
                ->where('status', '1')
                ->where('is_deleted', 0)
                ->get(['id', 'column_name']);

            foreach ($eavLabels as $label) {
                if (!array_key_exists($label->column_name, $input)) continue;
                $val = $input[$label->column_name];
                if ($val === null || $val === '') continue;
                \Illuminate\Support\Facades\DB::connection("mysql_$clientId")
                    ->table('crm_lead_field_values')
                    ->upsert(
                        ['lead_id' => $leadId, 'label_id' => $label->id, 'column_name' => $label->column_name,
                         'value_text' => trim((string)$val), 'created_at' => \Carbon\Carbon::now(), 'updated_at' => \Carbon\Carbon::now()],
                        ['lead_id', 'label_id'],
                        ['value_text', 'updated_at']
                    );
            }
        } catch (\Throwable $e) {}
    }

    public function view(Request $request, $id)
    {
        $clientId = $request->auth->parent_id;

        //Validation
        $arrValidationRules = $this->validateLeadInfo($clientId);
        $this->validate($request, $arrValidationRules);

        try {
            $objLead = Lead::on("mysql_$clientId")->findOrFail($id);


            $arrFormatLeadInfo = $this->formatLeadInfo($request->all(), $clientId);
            foreach ($arrFormatLeadInfo as $strLeadLabel => $strLeadValue) {
                //if ($objLead->$strLeadLabel != $strLeadValue)
                    $objLead->$strLeadLabel = $strLeadValue;
            }

            $objLead->saveOrFail();
            return $this->successResponse("Lead Updated Successfully", $objLead->toArray());
        } catch (ModelNotFoundException $exception) {
            return $this->failResponse("Lead Not Found", [
                "Invalid Lead id: $id"
            ], $exception, 404);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to update Lead", [
                $exception->getMessage()
            ], $exception, 404);
        }
    }


    //create lead from another domain
    public function createLead(Request $request)
    {
        //return $request->all();
        $clientId = $request->auth->parent_id;
        $domain_list = $this->getPortalBaseUrl($clientId);
        //Validation
        $arrValidationRules = $this->validateLeadInfo($clientId);
        //$this->validate($request, $arrValidationRules);

        try
        {

            $checkObjLead = Lead::on("mysql_$clientId")->where('phone_number',$request->phone_number)->orWhere('email',$request->email)->get()->first();
           // return $this->successResponse("Lead Added Successfully", [$checkObjLead]);
            
            if(empty( $checkObjLead ))
            {
                //return 1;
                $objLead = new Lead($request->all());
                if(isset($objLead->dob))
                    $objLead->dob = \Carbon\Carbon::parse($objLead->dob)->format('Y-m-d');
                   // $objLead->phone = $request->phone_number;
                $objLead->setConnection("mysql_$clientId");
                $objLead->saveOrFail();
                $lastId = $objLead->id;
                $unique_token = $this->generateCode();
                $objLeadUpdate = Lead::on("mysql_$clientId")->findOrFail($lastId);

                $merchant_url = $domain_list . '/merchant/customer/app/index/' . $clientId . '/' . $lastId . '/' . $unique_token;
                $url = '<a href="' . $merchant_url . '">Click Here</a>';

               // $url = $domain_list.$clientId.'/'.$lastId.'/'.$unique_token;
                $objLeadUpdate->unique_url = $url;
                $objLeadUpdate->unique_token = $unique_token;
                $objLeadUpdate->created_by = $request->auth->id;
                $objLeadUpdate->assigned_to = $request->auth->id;

                $objLeadUpdate->save();

                return $this->successResponse("Lead Added Successfully", $objLead->toArray());
            }
            else
            {
                return $this->failResponse("Lead already Added", $checkObjLead->toArray());
            }
        }
        catch (\Exception $exception)
        {
            return $this->failResponse("Failed to create Lead ", [
                $exception->getMessage()
            ], $exception, 500);
        }
    }

    public static function generateCode($length = 35)
    {
        $characters = 'abcdefghijklmnopqrstuvwxyz0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < $length; $i++)
        {
            $randomString .= $characters[rand(0, $charactersLength - 1)];
        }
        return $randomString;
    }

    public function domainList(Request $request)
    {
        try
        {

            $clientId = $request->auth->parent_id;
            $domain_list = [];
            //$domain_list = DomainList::on("master")->get()->all();

            $domain_list = DomainList::on("master")->where('client_id',$request->auth->parent_id)->get()->all();
                        
            return $this->successResponse("Domain List", $domain_list);
        }
        catch (\Throwable $exception)
        {
            return $this->failResponse("Failed to domain_list ", [$exception->getMessage()], $exception, $exception->getCode());
        }
    }


    public function createDocument(Request $request, int $clientId)
    {
        //$clientId = 3;
         $this->validate($request, ['document_name' => 'required|string|max:255', 'document_type' => 'required|string', 'lead_id' => 'required|int']);
        try
        {
            $Documents = new Documents();
            $Documents->setConnection("mysql_$clientId");
            $Documents->lead_id = $request->lead_id;
            $Documents->document_name = $request->document_name;
            $Documents->document_type = $request->document_type;
            $Documents->file_name = $request->file_name;
            $Documents->file_size = $request->file_size;

            $Documents->saveOrFail();
            return $this->successResponse("Document Added Successfully", $Documents->toArray());
        }
        catch (\Exception $exception)
        {
            return $this->failResponse("Failed to create Documents ", [
                $exception->getMessage()
            ], $exception, 500);
        }
    }


     public function createNotification(Request $request, int $leadId, int $clientId)
    {
        try
        {
             $objLead = Lead::on("mysql_$clientId")->findOrFail($leadId);

           // return $this->successResponse("Notification Added Successfully", $objLead->toArray());


            $name = $objLead->first_name.' '.$objLead->last_name;

            $Notification = new Notification();
            $Notification->setConnection("mysql_$clientId");
            $Notification->user_id = 0;//$request->auth->id;
            $Notification->lead_id = $leadId;
           // $Notification->message = "<a href='#'>".$name."</a> ( ".$leadId." )".$request->message;

            $Notification->message = $request->message;

            
            $Notification->type = '0';
            $Notification->saveOrFail();



             $messageData = array(
                "lead_id" => $leadId,
                "message" => $request->message,
                "user_id" => 0,
                'type' => 0,
                'mailable' =>"emails.crm-generic"


            );

          //  $data = array('request' => $request);

            $notificationData = [
                "action" => "notification",
                "user" => $messageData
            ];



            //dispatch(new SendCrmNotificationEmail($clientId, $notificationData, 'notification'))->onConnection("database");

            $notificationController = new NotificationController();
            $notificationController->sendCrmNotification($clientId, $notificationData, 'notification');
            // $notificationController->sendCrmNotificationMerchant($clientId, $notificationData, 'notification');

            return $this->successResponse("Notification Added Successfully", $Notification->toArray());
        }
        catch (\Exception $exception)
        {
            return $this->failResponse("Failed to create Notification ", [
                $exception->getMessage()
            ], $exception, 500);
        }

    }
}
