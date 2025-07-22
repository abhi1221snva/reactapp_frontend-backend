<?php

namespace App\Jobs;

use App\Model\Client\ListData;
use App\Model\Client\MarketingCampaignRuns;
use App\Model\Client\MarketingCampaignSchedule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MarketingCampaignSchedules extends Job
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
                Log::info("MarketingCampaignSchedules.handle", [
                    "clientId" => $this->clientId,
                    "processingId" => $this->processingId,
                    "lastLeadId" => $this->lastLeadId,
                    "status" => $this->status,
                    "searchRecords" => $searchRecords,
                    "attempts" => $this->attempts()
                ]);

                if (empty($this->processingId)) {
                    $this->processingId = \Ramsey\Uuid\Uuid::uuid4()->toString();
                    DB::connection($connection)->statement("UPDATE marketing_campaign_schedules SET processing_id='".$this->processingId."', status=2 WHERE status=1 AND run_time <= date_add(UTC_TIMESTAMP(), interval 5 minute) LIMIT 1");
                }

                $record = MarketingCampaignSchedule::on($connection)->where('processing_id', '=', $this->processingId)->first();
                if (!empty($record)) {
                    $leads = ListData::on($connection)->where('list_id', $record->list_id)->where([["id", ">", $record->last_lead_id]])->get()->sortBy("id")->all();
                    Log::info("MarketingCampaignSchedules.handle ({$this->clientId}/{$this->processingId}): leads:".count($leads));
                    $i = $record->scheduled_count;
                    $this->lastLeadId = $record->last_lead_id;
                    foreach ( $leads as $lead ) {
                        try {
                            $option_value = $record->list_column_name;
                            $send_to = $lead->$option_value;
                            $scheduled_time = $record->run_time;
                            $send_type = $record->send;

                            $marketingCampaignRuns = new MarketingCampaignRuns();
                            $marketingCampaignRuns->setConnection($connection);
                            $marketingCampaignRuns->schedule_id = $record->id;
                            $marketingCampaignRuns->lead_id = $lead->id;
                            $marketingCampaignRuns->send_type = $send_type;
                            if ($send_type == 2) {
                                $marketingCampaignRuns->send_to = $record->sms_country_code . $send_to;
                            } else {
                                $marketingCampaignRuns->send_to = $send_to;
                            }
                            $marketingCampaignRuns->scheduled_time = $scheduled_time;
                            $marketingCampaignRuns->status = 1;
                            $marketingCampaignRuns->saveOrFail();
                            $this->lastLeadId = $lead->id;
                            $i++;

                            $now = microtime(true);
                            $executionTime = ($now - $start);
                            Log::info("MarketingCampaignSchedules.handle ({$this->clientId}/{$this->processingId})", [
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
                                dispatch(new MarketingCampaignSchedules($this->clientId, $this->processingId, $this->lastLeadId, $this->status))->onConnection("database")->onQueue("mc-schedules");
                                $searchRecords = false;
                                break;
                            }
                        } catch (\Throwable $throwable) {
                            Log::error("MarketingCampaignSchedules.record.error", buildContext($throwable, [
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
            Log::error("MarketingCampaignSchedules.job.error", buildContext($throwable, [
                "clientId" => $this->clientId,
                "processingId" => $this->processingId,
                "lastLeadId" => $this->lastLeadId,
                "status" => $this->status
            ]));
        }
    }
}
