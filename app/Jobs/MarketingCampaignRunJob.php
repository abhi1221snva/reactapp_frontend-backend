<?php

namespace App\Jobs;

use App\Mail\GenericMail;
use App\Model\Client\EmailTemplete;
use App\Model\Client\Label;
use App\Model\Client\ListData;
use App\Model\Client\ListHeader;
use App\Model\Client\MarketingCampaignRuns;
use App\Model\Client\MarketingCampaignSchedule;
use App\Model\Client\SmsTemplate;
use App\Model\Client\SmtpSetting;
use App\Model\Client\wallet;
use App\Model\Dids;
use App\Model\Sms;
use App\Model\User;
use App\Services\MailService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MarketingCampaignRunJob extends Job
{
    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */

    private $clientId;
    private $schedules = [];
    private $listLabels = [];
    private $listColumns = [];

    /**
     * DailyCallReportJob constructor.
     * @param int $clientId
     */
    public function __construct(int $clientId)
    {
        $this->clientId = $clientId;
    }

    /**
     * Execute the job.
     *
     */
    public function handle()
    {
        echo "MarketingCampaignRunJob.handle($this->clientId)\n";
        $connection = 'mysql_' . $this->clientId;
        $searchRecords = true;

        $start = microtime(true);
        $executionTime = 0;
        echo "SendType\tLeadId\tExecutionTime\n";
        do {
            $uuid = \Ramsey\Uuid\Uuid::uuid4()->toString();
            DB::connection($connection)->statement("UPDATE marketing_campaign_runs SET processing_id='$uuid', status=2, start_time=UTC_TIMESTAMP() WHERE status=1 AND scheduled_time <= UTC_TIMESTAMP() LIMIT 1");
            $record = MarketingCampaignRuns::on($connection)->where('processing_id', '=', $uuid)->first();
            if (!empty($record)) {
                try {
                    $runStatus = 2; #2 - processing

                    #From schedule_id get email/sms template content
                    #From schedule_id get email/sms sending setting
                    $schedule = $this->getSchedule($record->schedule_id);

                    #Using lead_id, replace all the placeholders from template content
                    #Pick lead data
                    $leadData = ListData::on($connection)->where('list_id', '=', $schedule['listId'])->where('id', '=', $record->lead_id)->first();

                    //fetch user package details for charges calculation
                    //make sure MarketingCampaignSchedule Obj exist if not then fetch
                    if (!$this->schedules[$record->schedule_id]["model"]){
                        $this->schedules[$record->schedule_id]["model"] = MarketingCampaignSchedule::on('mysql_'.$this->clientId)->findOrFail($record->schedule_id);
                    }

                    $intUserId = $this->schedules[$record->schedule_id]["model"]->created_by;
                    $intCharge = 0;

                    //fetch package details
                    $isFree = $intCharge = $currencyCode = $clientPackageId = NULL;
                    $user = new User();
                    $user->id = $intUserId;
                    $user->parent_id = $this->clientId;
                    $package = $user->getAssignedUserPackage(true);

                    echo "{$schedule['sendType']}\t\t{$record->lead_id}\t{$executionTime}\n";
                    if ($schedule['sendType'] == 'email') {
                        $emailBody = $schedule['emailBody'];
                        $emailSubject = $schedule['emailSubject'];

                        foreach ( $this->listColumns[$schedule['listId']] as $option => $column ) {
                            $replace = "[[". $column["label"]."]]";
                            $emailBody = str_replace($replace, $leadData->$option, $emailBody);
                            $emailSubject = str_replace($replace, $leadData->$option, $emailSubject);
                        }

                        $from = [
                            "address" => $schedule['smtpSetting']->from_email,
                            "name" => $schedule['smtpSetting']->from_name
                        ];
                        /*Log::info("email content", [
                            "emailBody" => $emailBody,
                            "emailSubject" => $emailSubject
                        ]);*/

                        $genericMail = new GenericMail(
                            $emailSubject,
                            $from,
                            $emailBody
                        );
                        try {
                            $mailService = new MailService($this->clientId, $genericMail, $schedule['smtpSetting']);
                            $mailService->sendEmail($record->send_to);
                            $runStatus = 3; #3 - sent

                            //Billing part for Email
                            if(empty($package)){
                                //No charge for Admin
                                $isFree = 1;
                                $intCharge = 0;
                            } else {
                                //Calculate email charges
                                if($package->free_emails > 0){
                                    $isFree = 1;
                                    $intCharge = 0;

                                    //Deduct free balance
                                    DB::connection('mysql_'.$this->clientId)->table('user_packages')->where('id',$package->user_package_id)->decrement('free_emails',1);

                                } else {
                                    //calculate sms charges
                                    $intCharge = $package->rate_per_email;
                                    $isFree = 0;

                                    //deduct amount from client_xxx.wallet
                                    $boolResponse = wallet::debitCharge($intCharge, $this->clientId, $package->currency_code);
                                    if (!$boolResponse) {
                                        Log::error("MarketingCampaignRunProcess.sendSms.errorResponse", [
                                            "clientId" => $this->clientId,
                                            "record" => $record->toArray(),
                                            "response" => "Failed to update Wallet"
                                        ]);
                                    }
                                }
                                $currencyCode = $package->currency_code;
                                $clientPackageId = $package->id;
                            }

                        } catch (\Throwable $throwable) {
                            $runStatus = 5; #5 - failed

                            Log::error("MarketingCampaignRunProcess.sendEmail", buildContext($throwable, [
                                "clientId" => $this->clientId,
                                "record" => $record->toArray()
                            ]));
                        }

                    } elseif ($schedule['sendType'] == 'sms') {
                        foreach ( $this->listColumns[$schedule['listId']] as $option => $column ) {
                            $schedule['smsContent'] = str_replace("{". $column["label"]."}", $leadData->$option, $schedule['smsContent']);
                        }
                        Log::info("sms content", [
                            "smsFrom" => $schedule['smsFrom'],
                            "smsTo" => $record->send_to,
                            "smsContent" => $schedule['smsContent']
                        ]);

                        #send the email/sms to send_to
                        try {
                            $api = config('sms.sms_api.value');
                            $access = config('sms.sms_access.value');
                            $sms_url = config('sms.sms_access_url.value');

                            $data_array = array();
                            $data_array['to'] = $record->send_to;
                            $data_array['from'] = $schedule['smsFrom'];
                            $data_array['text'] = $schedule['smsContent'];
                            $json_data_to_send = json_encode($data_array);

                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, $sms_url);
                            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
                            curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data_to_send);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
                            curl_setopt($ch, CURLOPT_HTTPHEADER, array("Content-Type: application/json", "Authorization: Basic " . base64_encode("$api:$access")));

                            $result = curl_exec($ch);
                            $res = json_decode($result);
                            if ($res->status == 1) {
                                $runStatus = 3; #3 - sent

                                //Billing part for SMS
                                if(empty($package)){
                                    //No charge for Admin
                                    $isFree = 1;
                                    $intCharge = 0;
                                } else {
                                    //Calculate SMS charges
                                    if($package->free_sms > 0){
                                        $isFree = 1;
                                        $intCharge = 0;

                                        //Deduct free balance
                                        DB::connection('mysql_'.$this->clientId)->table('user_packages')->where('id',$package->user_package_id)->decrement('free_sms',1);

                                    } else {
                                        //calculate sms charges
                                        $intCharge = $package->rate_per_sms;
                                        $isFree = 0;

                                        //deduct amount from client_xxx.wallet
                                        $boolResponse = wallet::debitCharge($intCharge, $this->clientId, $package->currency_code);
                                        if (!$boolResponse) {
                                            Log::error("MarketingCampaignRunProcess.sendSms.errorResponse", [
                                                "clientId" => $this->clientId,
                                                "record" => $record->toArray(),
                                                "response" => "Failed to update Wallet"
                                            ]);
                                        }
                                    }
                                    $currencyCode = $package->currency_code;
                                    $clientPackageId = $package->id;
                                }

                                $smsObj = new Sms;
                                $smsObj->setConnection($connection);
                                $smsObj->number = $record->send_to;
                                $smsObj->did = $schedule['smsFrom'];
                                $smsObj->message = $schedule['smsContent'];
                                $smsObj->operator = 'nexmo';
                                $smsObj->type = 'outgoing';
                                $smsObj->currency_code = $currencyCode;
                                $smsObj->client_package_id = $clientPackageId;
                                $smsObj->user_id = $intUserId;
                                $smsObj->charge = $intCharge;
                                $smsObj->isFree = $isFree;
                                $smsObj->save();

                            } else {
                                $runStatus = 5; #5 - failed

                                Log::error("MarketingCampaignRunProcess.sendSms.errorResponse", [
                                    "clientId" => $this->clientId,
                                    "record" => $record->toArray(),
                                    "response" => $res
                                ]);
                            }
                        } catch (\Throwable $throwable) {
                            $runStatus = 5; #5 - failed

                            Log::error("MarketingCampaignRunProcess.sendSms.error", buildContext($throwable, [
                                "clientId" => $this->clientId,
                                "record" => $record->toArray()
                            ]));
                        }
                    }

                    //update client_xxx.marketing_campaign_runs
                    $record->currency_code = $currencyCode;
                    $record->client_package_id = $clientPackageId;
                    $record->user_id = $intUserId;
                    $record->charge = $intCharge;
                    $record->isFree = $isFree;
                    $record->save();

                } catch (\Throwable $throwable) {
                    $runStatus = 5; #5 - failed
                    Log::error("MarketingCampaignRunProcess.error", buildContext($throwable, [
                        "clientId" => $this->clientId,
                        "record" => $record->toArray()
                    ]));
                    #Since this is generic error exit the process
                    $searchRecords = false;
                }

                $record->status = $runStatus;
                if ($runStatus == 3) $record->sent_time = Carbon::now('UTC');
                $record->processing_id = null;
                $record->save();
            } else {
                $searchRecords = false;
            }
            if ($searchRecords) {
                $now = microtime(true);
                $executionTime = ($now - $start);
                #Exit if running more than 30 seconds
                if ($executionTime > 25) {
                    $searchRecords = false;
                    dispatch(new MarketingCampaignRunJob($this->clientId))->onConnection("mc_run_job");
                }
            }
        } while ($searchRecords);
    }

    private function getSchedule(int $scheduleId)
    {
        if (isset($this->schedules[$scheduleId])) return $this->schedules[$scheduleId];

        $connection = "mysql_{$this->clientId}";
        $mcs = MarketingCampaignSchedule::on($connection)->findOrFail($scheduleId);

        $this->schedules[$scheduleId] = [];
        $this->schedules[$scheduleId]['listId'] = $mcs->list_id;
        $this->schedules[$scheduleId]["model"] = $mcs;

        if (empty($this->listLabels)) {
            $labels = Label::on($connection)->get()->all();
            foreach ($labels as $label) $this->listLabels[$label->id] = $label->title;
        }

        if (!isset($this->listColumns[$mcs->list_id])) {
            $this->listColumns[$mcs->list_id] = [];
            $headers = ListHeader::on($connection)->where("list_id", "=", $mcs->list_id)->get()->all();
            foreach ( $headers as $header ) {
                $this->listColumns[$mcs->list_id][$header['column_name']] = [
                    "header" => $header['header'],
                    "label" => $this->listLabels[$header['label_id']]
                ];
            }
        }

        if ($mcs->send == 1) {
            #Email
            $this->schedules[$scheduleId]['sendType'] = 'email';

            $emailTemplate = EmailTemplete::on($connection)->findOrFail($mcs->email_template_id);
            $this->schedules[$scheduleId]['emailBody'] = $emailTemplate->template_html;
            $this->schedules[$scheduleId]['emailSubject'] = $emailTemplate->subject;

            $smtpSetting = SmtpSetting::on($connection)->findOrFail($mcs->email_setting_id);
            $this->schedules[$scheduleId]['smtpSetting'] = $smtpSetting;
        } elseif ($mcs->send == 2) {
            #SMS
            $this->schedules[$scheduleId]['sendType'] = 'sms';
            $smsTemplate = SmsTemplate::on($connection)->findOrFail($mcs->sms_template_id);
            $this->schedules[$scheduleId]['smsContent'] = $smsTemplate->templete_desc;

            $did = Dids::on($connection)->findOrFail($mcs->sms_setting_id);
            $this->schedules[$scheduleId]['smsFrom'] = $did->cli;
        }

        return $this->schedules[$scheduleId];
    }
}

