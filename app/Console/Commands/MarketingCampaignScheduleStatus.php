<?php

namespace App\Console\Commands;

use App\Model\Client\MarketingCampaignRuns;
use App\Model\Client\MarketingCampaignSchedule;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MarketingCampaignScheduleStatus extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:mc:schedule-status {--clientId=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check Status Marketing Campaign Email and Sms for all clients';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $clientId = $this->option('clientId');
        if ($clientId) {
            $clients = \App\Model\Master\Client::where("id", $clientId)->get()->all();
        } else {
            $clients = \App\Model\Master\Client::all();
        }
        foreach ( $clients as $client ) {
            $this->info("MarketingCampaignStatus({$client->id})");
            try {
                $connection = "mysql_{$client->id}";
                $records = MarketingCampaignSchedule::on($connection)->whereIn('status', [4, 5])->where('run_time', '<=', DB::raw("UTC_TIMESTAMP()"))->get()->all();
                foreach ( $records as $record ) {
                    if (!empty($record)) {
                        $recordSentCount = MarketingCampaignRuns::on($connection)->where('status', '=', 3)->where('schedule_id', $record->id)->count();
                        $recordFailedCount = MarketingCampaignRuns::on($connection)->whereIn('status', [5, 6])->where('schedule_id', $record->id)->count();
                        if ($record->scheduled_count <= $recordSentCount + $recordFailedCount) {
                            DB::connection($connection)->statement("UPDATE marketing_campaign_schedules SET sent_count='$recordSentCount',failed_count='$recordFailedCount', complete_time=UTC_TIMESTAMP(), status=6 WHERE id='{$record->id}'");
                        } elseif ($recordSentCount + $recordFailedCount > 0) {
                            DB::connection($connection)->statement("UPDATE marketing_campaign_schedules SET sent_count='$recordSentCount',failed_count='$recordFailedCount', status=5 WHERE id='{$record->id}'");
                        }
                    }
                }
            } catch (\Throwable $throwable) {
                Log::error("MarketingCampaignScheduleStatus.error", buildContext($throwable, [
                    "clientId" => $client->id
                ]));
                $this->error("clientId {$client->id} | Error " . $throwable->getMessage());
            }
        }
    }
}
