<?php

namespace App\Jobs;

use App\Model\Client\DripCampaignRuns;
use App\Model\Client\DripCampaignSchedule;
use App\Model\Client\LeadStatus;
use App\Model\Client\Lead;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DripCampaignSchedules extends Job
{
    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */

    private $clientId;
    private $lastLeadId;
    private $status;
    private $processingId;

    public function __construct(int $clientId, string $processingId = null, int $lastLeadId = 0, int $status = 1)
    {
        $this->clientId = $clientId;
        $this->processingId = $processingId;
        $this->lastLeadId = $lastLeadId;
        $this->status = $status;
    }

    /**
     * Execute the job.
     *
     */
    public function handle()
    {
        $connection = 'mysql_' . $this->clientId;
        $searchRecords = true;
        $start = microtime(true);

        try {
            do {
                Log::info("DripCampaignSchedules.handle", [
                    "clientId" => $this->clientId,
                    "processingId" => $this->processingId,
                    "lastLeadId" => $this->lastLeadId,
                    "status" => $this->status,
                    "searchRecords" => $searchRecords,
                    "attempts" => $this->attempts()
                ]);

                if (empty($this->processingId)) {
                    $this->processingId = \Ramsey\Uuid\Uuid::uuid4()->toString();
                    // DB::connection($connection)->statement("UPDATE drip_campaign_schedules SET processing_id='".$this->processingId."', status=2 WHERE status=1 AND run_time <= date_add(UTC_TIMESTAMP(), interval 5 minute) LIMIT 1");
                      // Check if there are any rows to process
                $recordCount = DB::connection($connection)->table('drip_campaign_schedules')
                ->where('status', '=', 1)
                ->count();

            if ($recordCount === 0) {
                Log::info("DripCampaignSchedules.handle: No rows found in the drip_campaign_schedules table.");
                return; // Exit the job
            }
            $updatedRows = DB::connection($connection)->statement("
                    UPDATE drip_campaign_schedules 
                            SET processing_id = '".$this->processingId."', 
                                status = 2 
                            WHERE status = 1 
                            AND (
                                (schedule = 'daily' AND run_time <= DATE_ADD(NOW(), INTERVAL 5 MINUTE)) 
                                OR 
                                (schedule = 'weekly' AND schedule_day = DAYNAME(CURDATE()) AND run_time <= DATE_ADD(NOW(), INTERVAL 5 MINUTE))
                            ) 
                            LIMIT 1

                            ");
                            if ($updatedRows === 0) {
                                Log::info("DripCampaignSchedules.handle: No rows matched the update criteria.");
                                return; // Exit the job
                            }

                }

                $record = DripCampaignSchedule::on($connection)->where('processing_id', '=', $this->processingId)->first();
                Log::info("DripCampaignSchedules.leads",['record'=>$record]);
                if (empty($record)) {
                    Log::info("DripCampaignSchedules.handle: No matching record found for processing ID.");
                    return; // Exit the job
                }
                if (!empty($record)) {
                    $leadStatusRecord = LeadStatus::on($connection)
                    ->where('id', $record->lead_status_id)
                    ->first(); // Fetch the record
                    Log::info("DripCampaignSchedules.leads",['leadStatusRecord'=>$leadStatusRecord]);

                if ($leadStatusRecord) {
                    $leadStatusTitle = $leadStatusRecord->lead_title_url; // Extract the title
                    // Step 2: Query the leads table with the matching title
                    $leads = Lead::on($connection)
                        ->where('lead_status', $leadStatusTitle)
                        ->get(); // Get all matching leads
                        Log::info("DripCampaignSchedules.leads",['leads'=>$leads]);

                }
                    Log::info("DripCampaignSchedules.handle ({$this->clientId}/{$this->processingId}): leads:".count($leads));
                    $i = $record->scheduled_count;
                    $this->lastLeadId = $record->last_lead_id;
                    foreach ( $leads as $lead ) {
                        try {
                            $send_to = $lead->email;
                            $scheduled_time = $record->run_time;
                            $send_type = $record->send;

                            $DripCampaignRuns = new DripCampaignRuns();
                            $DripCampaignRuns->setConnection($connection);
                            $DripCampaignRuns->schedule_id = $record->id;
                            $DripCampaignRuns->lead_id = $lead->id;
                            $DripCampaignRuns->send_type = $send_type;                         
                            $DripCampaignRuns->scheduled_time = $scheduled_time;
                            $DripCampaignRuns->send_to = $send_to;
                            $DripCampaignRuns->lead_status_id = $record->lead_status_id;
                            $DripCampaignRuns->schedule = $record->schedule;
                            $DripCampaignRuns->schedule_day = $record->schedule_day;
                            $DripCampaignRuns->user_id = $record->created_by;

                            $DripCampaignRuns->status = 1;
                            $DripCampaignRuns->saveOrFail();
                            $this->lastLeadId = $lead->id;
                            $i++;

                            $now = microtime(true);
                            $executionTime = ($now - $start);
                            Log::info("dripCampaignSchedules.handle ({$this->clientId}/{$this->processingId})", [
                                "count" => $i,
                                "list_id" => $lead->list_id,
                                "lastLeadId" => $this->lastLeadId,
                                "executionTime" => $executionTime
                            ]);

                            #Exit if running more than 30 seconds
                            if ($executionTime > 25) {
                                $record->last_lead_id = $this->lastLeadId;
                                $record->scheduled_count = $i;
                                $record->status = 2;
                                $record->saveOrFail();
                                dispatch(new DripCampaignSchedules($this->clientId, $this->processingId, $this->lastLeadId, $this->status))->onConnection("database")->onQueue("dc-schedules");
                                $searchRecords = false;
                                break;
                            }
                        } catch (\Throwable $throwable) {
                            Log::error("dripCampaignSchedules.record.error", buildContext($throwable, [
                                "clientId" => $this->clientId,
                                "record" => $record->toArray()
                            ]));
                            $record->last_lead_id = $this->lastLeadId;
                            $record->status = 3;
                            $record->scheduled_count = $i;
                            $record->saveOrFail();

                            throw $throwable;
                        }
                    }
                    if ($searchRecords) {
                        $record->last_lead_id = $this->lastLeadId;
                        $record->scheduled_count = $i;
                        $record->status = 4;  //for queued
                        $record->saveOrFail();
                        $this->processingId = null;
                    }

                } else {
                    //No more records found to process hence exit
                    $searchRecords = false;
                }
            } while ($searchRecords);
        } catch (\Throwable $throwable) {
            Log::error("DripCampaignSchedules.job.error", buildContext($throwable, [
                "clientId" => $this->clientId,
                "processingId" => $this->processingId,
                "lastLeadId" => $this->lastLeadId,
                "status" => $this->status
            ]));
        }

     
    }
}
