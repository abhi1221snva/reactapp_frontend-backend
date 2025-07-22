<?php

namespace App\Jobs;

use App\Mail\GenericMail;
use App\Model\Client\CrmEmailTemplate;
use App\Model\Client\DripCampaignRuns;
use App\Model\Client\DripCampaignSchedule;
use App\Model\Client\wallet;
use App\Model\Client\LeadStatus;
use App\Model\Client\Lead;
use App\Model\Dids;
use App\Model\User;
use App\Services\CrmMailService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Model\Client\EmailSetting;

class DripCampaignRunJob extends Job
{
    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */

    private $clientId;
    private $schedules = [];

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
        echo "DripCampaignRunJob.handle($this->clientId)\n";
        $connection = 'mysql_' . $this->clientId;
        $searchRecords = true;
    
        $start = microtime(true);
        $executionTime = 0;
        echo "SendType\tLeadId\tExecutionTime\n";
    
        do {
            $uuid = \Ramsey\Uuid\Uuid::uuid4()->toString();
            echo "Generated UUID: $uuid\n";
    
            // Update query
            // DB::connection($connection)->statement("UPDATE drip_campaign_runs SET processing_id='$uuid', status=2, start_time=UTC_TIMESTAMP() WHERE status=1 AND scheduled_time <= UTC_TIMESTAMP() LIMIT 1");
            DB::connection($connection)->statement("
            UPDATE drip_campaign_runs 
            SET processing_id = '$uuid', 
                status = 2, 
                start_time = NOW() 
            WHERE status = 1 
            AND scheduled_time <= NOW() 
            AND (
                schedule = 'daily' OR 
                (schedule = 'weekly' AND schedule_day = DAYNAME(CURDATE()))
            )
            LIMIT 1;

            ");

            echo "Update query executed.\n";
            // Fetch the record
            $record = DripCampaignRuns::on($connection)->where('processing_id', '=', $uuid)->first();
            echo "Fetched Record: " . print_r($record, true) . "\n";
            if (empty($record)) {
                echo "No matching record found in drip_campaign_runs.\n";
                $searchRecords = false;
                break; // Exit the loop if no record is found
            }
    
            // Dump the record to check its structure
            if (!empty($record)) {
                try {
                    $runStatus = 2; #2 - processing

                    #From schedule_id get email
                    $schedule = $this->getSchedule($record->schedule_id);
                    
                        echo "get schedule: " . print_r($schedule, true) . "\n";
                    
                        if (empty($schedule)) {
                            echo "No schedule found for schedule ID: {$record->schedule_id}\n";
                            continue; // Skip processing this record
                        }
            
                    #Using lead_id, replace all the placeholders from template content
                    #Pick lead data
                    $leadData = LeadStatus::on($connection)->where('id', '=', $schedule['leadStatusId'])->first();
                    echo "get data: $leadData\n";
                    if (empty($leadData)) {
                        echo "No lead data found for lead status ID: {$schedule['leadStatusId']}\n";
                        Log::warning("No lead data for schedule ID: {$record->schedule_id}");
                        continue; // Skip processing this record
                    }
        
                    //fetch user package details for charges calculation
                    if (!isset($this->schedules[$record->schedule_id]["model"])){
                        $this->schedules[$record->schedule_id]["model"] = DripCampaignSchedule::on('mysql_'.$this->clientId)->findOrFail($record->schedule_id);
                    }
                    if (empty($this->schedules[$record->schedule_id]["model"])) {
                        echo "No DripCampaignSchedule found for schedule ID: {$record->schedule_id}\n";
                        Log::warning("No DripCampaignSchedule for schedule ID: {$record->schedule_id}");
                        continue; // Skip processing this record
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

                        $leadStatusData =$leadData->lead_title_url ;

                        $leadDataa = Lead::on($connection)->where('lead_status',$leadStatusData)->first();
                        if ($leadDataa) {
                            // Replace placeholders in the email body and subject
                            $leadDataArray = $leadDataa->toArray();
    
                            // Initialize the placeholders array
                            $placeholders = [];
                            // Replace placeholders in the email body and subject
                            foreach ($leadDataArray as $column => $value) {
                                // Dynamically create the placeholder, e.g., '[[first_name]]' for the 'first_name' column
                                $placeholders["[[$column]]"] = $value ?? ''; // Default empty string if value is null
                            }
                    
                            $emailBody = str_replace(array_keys($placeholders), array_values($placeholders), $emailBody);
                            $emailSubject = str_replace(array_keys($placeholders), array_values($placeholders), $emailSubject);
                        } else {
                            // Handle missing lead data
                            Log::error("Lead data not found for lead ID: {$schedule['leadStatusId']}");
                            continue; // Skip processing this record
                        }

                        $from = [
                            "address" => $schedule['smtpSetting']->from_email,
                            "name" => $schedule['smtpSetting']->from_name
                        ];
                        /*Log::info("email content", [
                            "emailBody" => $emailBody,
                            "emailSubject" => $emailSubject
                        ]);*/

                 
                        try {
                            $data_array = [
                                'subject' => $emailSubject,
                                'content' => $emailBody, // Add this line

                                // Include other data your view might need here
                            ];
                            $mailable ="emails.crm-generic";

                            $mailService = new CrmMailService($this->clientId,$mailable,$schedule['smtpSetting'],$data_array);
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

                                } 
                                $currencyCode = $package->currency_code;
                                $clientPackageId = $package->id;
                            }

                        } catch (\Throwable $throwable) {
                            $runStatus = 5; #5 - failed

                            Log::error("dripCampaignRunProcess.sendEmail", buildContext($throwable, [
                                "clientId" => $this->clientId,
                                "record" => $record->toArray()
                            ]));
                        }

                   
                       
                    }

                    //update client_xxx.drip_campaign_runs
                    $record->currency_code = $currencyCode;
                    $record->client_package_id = $clientPackageId;
                    $record->user_id = $intUserId;
                    $record->charge = $intCharge;
                    $record->isFree = $isFree;
                    $record->save();

                } catch (\Throwable $throwable) {
                    $runStatus = 5; #5 - failed
                    Log::error("DripCampaignRunProcess.error", buildContext($throwable, [
                        "clientId" => $this->clientId,
                        "record" => $record->toArray()
                    ]));
                    #Since this is generic error exit the process
                    $searchRecords = false;
                }

                $record->status = $runStatus;
                if ($runStatus == 3) 
                $record->sent_time = Carbon::now('UTC');
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
                    dispatch(new DripCampaignRunJob($this->clientId))->onConnection("dc_run_job");
                }
            }
        } while ($searchRecords);
    }

   

    private function getSchedule(int $scheduleId)
    {
        if (isset($this->schedules[$scheduleId])) return $this->schedules[$scheduleId];

        $connection = "mysql_{$this->clientId}";
        $mcs = DripCampaignSchedule::on($connection)->findOrFail($scheduleId);
        echo "find id: $mcs\n";

        $this->schedules[$scheduleId] = [];
        $this->schedules[$scheduleId]['leadStatusId'] = $mcs->lead_status_id;
        $this->schedules[$scheduleId]["model"] = $mcs;

 

        if ($mcs->send == 1) {
            #Email
            $this->schedules[$scheduleId]['sendType'] = 'email';

            $emailTemplate = CrmEmailTemplate::on($connection)->findOrFail($mcs->email_template_id);
            $this->schedules[$scheduleId]['emailBody'] = $emailTemplate->template_html;
            $this->schedules[$scheduleId]['emailSubject'] = $emailTemplate->subject;

            $smtpSetting = EmailSetting::on($connection)->findOrFail($mcs->email_setting_id);
            $this->schedules[$scheduleId]['smtpSetting'] = $smtpSetting;
        } 

        return $this->schedules[$scheduleId];
    }
}