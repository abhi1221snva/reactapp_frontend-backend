<?php

namespace App\Http\Controllers;

use App\Services\OtpService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use App\Model\User;
use App\Model\Client\CrmLabel;
use App\Model\Client\SystemSetting;


use App\Model\Client\DocumentTypes;
use App\Model\Client\Lead;
use App\Model\Client\Documents;
use App\Model\Client\Notification;
use App\Model\Client\emailLog;
use App\Model\Client\SmtpSetting;
use Illuminate\Support\Facades\Log;
use App\Model\Master\Client;

use App\Services\MailService;
use App\Services\CrmMailService;
use App\Jobs\SendCrmNotificationEmail;

use Illuminate\Support\Carbon;
use App\Http\Controllers\NotificationController;







class MerchantController extends Controller
{

    /**
     * @OA\Get(
     *     path="/crm-system-settings/{clientId}",
     *     summary="Get list of companies (system settings) for a given client",
     *     description="Returns a list of companies or groups from the specified client's database connection.",
     *     tags={"Merchant"},
     *     security={{"Bearer":{}}},
     *
     *     @OA\Parameter(
     *         name="clientId",
     *         in="path",
     *         required=true,
     *         description="Client ID to fetch company list from its database",
     *         @OA\Schema(type="integer", example=3)
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="List of companies retrieved successfully",
     *         @OA\JsonContent(
     *             type="array",
     *             @OA\Items(
     *                 type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="company_name", type="string", example="Solivix Pharmaceuticals"),
     *                 @OA\Property(property="created_at", type="string", format="date-time", example="2025-04-22T10:00:00Z"),
     *                 @OA\Property(property="updated_at", type="string", format="date-time", example="2025-04-22T10:30:00Z")
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=500,
     *         description="Failed to retrieve list of companies",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Failed to list of groups")
     *         )
     *     )
     * )
     */


    public function companyList(Request $request, int $clientId)
    {


        try {
            //$clientId = 3;
            $group = [];
            $group = SystemSetting::on("mysql_$clientId")->orderBy('id', 'DESC')->get()->all();
            return $this->successResponse("Groups", $group);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to list of groups", [$exception->getMessage()], $exception, $exception->getCode());
        }
    }



    public function labelList(Request $request, int $clientId)
    {
        try {
            //$clientId = 3;
            $Label = [];
            $Label = CrmLabel::on("mysql_$clientId")->where('edit_mode', 1)->where('status', 1)->orderBy('display_order', 'ASC')->get()->all();
            return $this->successResponse("List of Label", $Label);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to Label ", [$exception->getMessage()], $exception, $exception->getCode());
        }
    }

    public function leadDetailsByToken(Request $request, $leadId, int $clientId)
    {
        //$clientId = $clientId;
        try {
            $arrLead = Lead::on("mysql_$clientId")->where('unique_token', $leadId)->get()->first()->toArray();
            return $this->successResponse("Lead info", $arrLead);
        } catch (ModelNotFoundException $exception) {
            throw new NotFoundHttpException("No Lead with id $leadId");
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to fetch lead info", [$exception->getMessage()], $exception);
        }
    }

    public function leadDetails(Request $request, int $leadId, int $clientId)
    {
        //$clientId = $clientId;
        try {
            $arrLead = Lead::on("mysql_$clientId")->findorfail($leadId)->toArray();

            $objLead = Lead::on("mysql_$clientId")->findOrFail($leadId);
            $objLead->mail_send = 1;
            $objLead->save();

            return $this->successResponse("Lead info", $arrLead);
        } catch (ModelNotFoundException $exception) {
            throw new NotFoundHttpException("No Lead with id $leadId");
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to fetch lead info", [$exception->getMessage()], $exception);
        }
    }

    public function typesList(Request $request, int $clientId)
    {
        try {
            //$clientId = 3;
            $document_types = [];
            $document_types = DocumentTypes::on("mysql_$clientId")->orderBy('id', 'DESC')->get()->all();
            return $this->successResponse("Document Types", $document_types);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to list of Document Types", [$exception->getMessage()], $exception, $exception->getCode());
        }
    }


    public function typeValueDocument(Request $request, int $clientId)
    {
        try {
            //$clientId = 3;
            $Documents = [];
            $Documents = DocumentTypes::on("mysql_$clientId")->where('type_title_url', $request->type)->where('status', 1)->get()->all();
            return $this->successResponse("List of Documents", $Documents);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to Documents ", [$exception->getMessage()], $exception, $exception->getCode());
        }
    }


    public function documentList(Request $request, int $leadId, int $clientId)
    {
        try {
            //$clientId = 3;
            $Documents = [];
            $Documents = Documents::on("mysql_$clientId")->where('lead_id', $leadId)->get()->all();
            return $this->successResponse("List of Documents", $Documents);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to Documents ", [$exception->getMessage()], $exception, $exception->getCode());
        }
    }

    /**
     * @OA\Put(
     *     path="/document/{clientId}",
     *     summary="Create a new document for a specific client",
     *     description="Stores a new document record in the database for the given client ID.",
     *     tags={"Merchant"},
     *     security={{"Bearer":{}}},
     *
     *     @OA\Parameter(
     *         name="clientId",
     *         in="path",
     *         required=true,
     *         description="Client ID whose database will be used to store the document",
     *         @OA\Schema(type="integer", example=3)
     *     ),
     *
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"document_name", "document_type", "lead_id"},
     *             @OA\Property(property="document_name", type="string", example="Contract.pdf"),
     *             @OA\Property(property="document_type", type="string", example="PDF"),
     *             @OA\Property(property="lead_id", type="integer", example=12),
     *             @OA\Property(property="file_name", type="string", example="contract_12_apr2025.pdf"),
     *             @OA\Property(property="file_size", type="string", example="235KB")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Document added successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Document Added Successfully"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="id", type="integer", example=101),
     *                 @OA\Property(property="lead_id", type="integer", example=12),
     *                 @OA\Property(property="document_name", type="string", example="Contract.pdf"),
     *                 @OA\Property(property="document_type", type="string", example="PDF"),
     *                 @OA\Property(property="file_name", type="string", example="contract_12_apr2025.pdf"),
     *                 @OA\Property(property="file_size", type="string", example="235KB"),
     *                 @OA\Property(property="created_at", type="string", format="date-time", example="2025-04-22T10:00:00Z"),
     *                 @OA\Property(property="updated_at", type="string", format="date-time", example="2025-04-22T10:00:00Z")
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=500,
     *         description="Failed to create Documents",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Failed to create Documents")
     *         )
     *     )
     * )
     */

    public function create(Request $request, int $clientId)
    {
        //$clientId = 3;
        $this->validate($request, ['document_name' => 'required|string|max:255', 'document_type' => 'required|string', 'lead_id' => 'required|int']);
        try {
            $Documents = new Documents();
            $Documents->setConnection("mysql_$clientId");
            $Documents->lead_id = $request->lead_id;
            $Documents->document_name = $request->document_name;
            $Documents->document_type = $request->document_type;
            $Documents->file_name = $request->file_name;
            $Documents->file_size = $request->file_size;

            $Documents->saveOrFail();
            return $this->successResponse("Document Added Successfully", $Documents->toArray());
        } catch (\Exception $exception) {
            return $this->failResponse("Failed to create Documents ", [
                $exception->getMessage()
            ], $exception, 500);
        }
    }

    public function update(Request $request, $leadId, $clientId)
    {



        /*
        if(!empty($request->signature_image))
        {

            $Documents = Documents::on("mysql_$clientId")->where('document_type','signature_application')->where('lead_id',$leadId)->get()->first();


        if(empty($Documents))
        {*/

        $filename = 'signed_application_' . time() . '.pdf';

        $Documents = new Documents();
        $Documents->setConnection("mysql_$clientId");
        $Documents->lead_id = $leadId;
        $Documents->document_name = 'Signed Application';
        $Documents->document_type = 'signature_application';
        $Documents->file_name = $filename;
        $Documents->file_size = '5KB';



        $Documents->saveOrFail();
        /*}

        }*/


        //$clientId = 3;

        //Validation
        $arrValidationRules = $this->validateLeadInfo($clientId);
        $this->validate($request, $arrValidationRules);

        try {
            $objLead = Lead::on("mysql_$clientId")->findOrFail($leadId);


            $arrFormatLeadInfo = $this->formatLeadInfo($request->all(), $clientId);
            foreach ($arrFormatLeadInfo as $strLeadLabel => $strLeadValue) {
                //if ($objLead->$strLeadLabel != $strLeadValue)
                $objLead->$strLeadLabel = $strLeadValue;
            }
            $objLead['owner_2_signature_date'] =  Carbon::now();

            $oldlead_status = $objLead->getOriginal('lead_status');
            $objLead->saveOrFail();
            $this->saveEavFields($clientId, (int)$leadId, $request->all());
            $objLead['old_lead_status'] =  $oldlead_status;
            $objLead['doc_file_name'] = $filename;

            return $this->successResponse("Lead Updated Successfully", $objLead->toArray());
        } catch (ModelNotFoundException $exception) {
            return $this->failResponse("Lead Not Found", [
                "Invalid Lead id: $leadId"
            ], $exception, 404);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to update Lead", [
                $exception->getMessage()
            ], $exception, 404);
        }
    }


    public function validateLeadInfo($clientId)
    {
        $arrLabels = CrmLabel::on("mysql_$clientId")->where('edit_mode', 1)->where('status', '1')->get()->toArray();


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
    public function formatLeadInfo($arrInputLeadInfo, $clientId)
    {
        // Only process column-backed labels; EAV labels are saved via saveEavFields()
        $arrLabels = CrmLabel::on("mysql_$clientId")
            ->where('edit_mode', 1)
            ->where('label_title_url', '!=', "unique_url")
            ->where('status', 1)
            ->where('storage_type', 'column')
            ->get()->toArray();

        foreach ($arrLabels as $arrLabel) {
            $columnName = $arrLabel['column_name'];
            $dataType   = $arrLabel['data_type'];

            if ($dataType == 'phone_number') {
                $arrInputLeadInfo[$columnName] = isset($arrInputLeadInfo[$columnName]) ? str_replace(['(', ')', '_', '-', ' '], '', $arrInputLeadInfo[$columnName]) : null;
            } else {
                $arrInputLeadInfo[$columnName] = isset($arrInputLeadInfo[$columnName]) && trim($arrInputLeadInfo[$columnName]) !== '' ? trim($arrInputLeadInfo[$columnName]) : null;
            }
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
                         'value_text' => trim((string)$val), 'created_at' => now(), 'updated_at' => now()],
                        ['lead_id', 'label_id'],
                        ['value_text', 'updated_at']
                    );
            }
        } catch (\Throwable $e) {}
    }

    public function formatLeadInfo_old($arrInputLeadInfo, $clientId)
    {
        //$arrLabels = CrmLabel::on("mysql_$clientId")->where('edit_mode',1)->where(["status" => 1])->get()->toArray();

        $arrLabels = CrmLabel::on("mysql_$clientId")->where('edit_mode', 1)->where('label_title_url', '!=', "unique_url")->where(["status" => 1])->get()->toArray();



        foreach ($arrLabels as $key => $arrLabel) {
            if ($arrLabel['data_type'] == 'phone_number')
                /*$arrInputLeadInfo[$arrLabel['column_name']] = str_replace(array('(',')', '_', '-',' '), array(''), $arrInputLeadInfo[$arrLabel['column_name']]);*/

                $arrInputLeadInfo[$arrLabel['column_name']] = (!empty($arrInputLeadInfo[$arrLabel['column_name']])) ? trim($arrInputLeadInfo[$arrLabel['column_name']]) : '';
        }
        return $arrInputLeadInfo;
    }

    public function createNotification(Request $request, int $leadId, int $clientId)
    {
        try {
            $objLead = Lead::on("mysql_$clientId")->findOrFail($leadId);

            // return $this->successResponse("Notification Added Successfully", $objLead->toArray());


            $name = $objLead->first_name . ' ' . $objLead->last_name;

            $Notification = new Notification();
            $Notification->setConnection("mysql_$clientId");
            $Notification->user_id = 0; //$request->auth->id;
            $Notification->lead_id = $leadId;
            // $Notification->message = "<a href='#'>".$name."</a> ( ".$leadId." )".$request->message;

            $Notification->message = "<b>@Customer</b> " . $request->message;


            $Notification->type = '0';
            $Notification->saveOrFail();



            $messageData = array(
                "lead_id" => $leadId,
                "message" => "<b>@Customer</b> " . $request->message,
                "user_id" => 0,
                'type' => 0,
                'mailable' => "emails.crm-generic"


            );

            //  $data = array('request' => $request);

            $notificationData = [
                "action" => "notification",
                "user" => $messageData
            ];



            // dispatch(new SendCrmNotificationEmail($clientId, $notificationData, 'notification'))->onConnection("database");

            $notificationController = new NotificationController();
            $notificationController->sendCrmNotification($clientId, $notificationData, 'notification');
            // $notificationController->sendCrmNotificationMerchant($clientId, $notificationData, 'notification');


            //dispatch(new ExtensionNotificationJob($request->auth->parent_id, $notificationData))->onConnection("database");


            // $this->sendEmailNotification($request,$leadId,$clientId);

            return $this->successResponse("Notification Added Successfully", $Notification->toArray());
        } catch (\Exception $exception) {
            return $this->failResponse("Failed to create Notification ", [
                $exception->getMessage()
            ], $exception, 500);
        }
    }


    public function sendEmailNotificationOLD($request, $leadId, $clientId)
    {
        $client = Client::findOrFail($clientId);
        $company_name = $client->company_name;

        $objLead = Lead::on("mysql_$clientId")->findOrFail($leadId);

        $name = $objLead->first_name . ' ' . $objLead->last_name;





        $smtp_setting = SmtpSetting::on("mysql_$clientId")->where('status', '1')->first();
        $mailable = "emails.crm-generic";
        $subject = 'Status Update - ' . $company_name . ' Lead Id - ' . $request->lead_id;


        $message = "<b>@Customer</b> " . $request->message;

        $data = array('subject' => $subject, 'content' => $message);
        $mailService = new CrmMailService($clientId, $mailable, $smtp_setting, $data);

        //$to = array('abhi4mca@gmail.com','mailme@rohitwanchoo.com');//env('SYSTEM_ADMIN_EMAIL'); //



        if (!empty($to)) {
            foreach ($to as $email) {
                $send = $mailService->sendEmail($email);
            }
        }


        //return response()->json(["success" => true]);

        //close send email abhishek
    }


    public function sendEmailGeneric(Request $request)
    {

        $this->validate(
            $request,
            [
                'toEmailId' => 'required',
                'subject' => 'required|string|max:255',
                'editor1' => 'required',

            ]
        );

        try {
            $clientId = $request->clientId;
            $smtp_setting = SmtpSetting::on("mysql_$clientId")->where('status', '1')->first();
            $mailable = "emails.generic";
            $to = $request->toEmailId;
            $data = array('subject' => $request->subject, 'content' => $request->editor1);

            $mailService = new MailService($clientId, $mailable, $smtp_setting, $data);
            $mailService->sendEmail($to);
            return response()->json(["success" => true]);
        } catch (\Throwable $exception) {
            Log::error(
                "MailController.sendMail.error",
                [
                    "message" => $exception->getMessage(),
                    "file" => $exception->getFile(),
                    "line" => $exception->getLine()
                ]
            );
            return response()->json(
                [
                    "success" => false,
                    "message" => $exception->getMessage()
                ]
            );
        }
    }
}
