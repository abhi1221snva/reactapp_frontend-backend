<?php

namespace App\Console\Commands;

use App\Model\Client\DripCampaignRuns;
use App\Model\Client\DripCampaignSchedule;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class DripCampaignScheduleStatus extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:dc:schedule-status {--clientId=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check Status Drip Campaign Email  for all clients';

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
    
        // Fetch clients based on the client ID option
        if ($clientId) {
            $clients = \App\Model\Master\Client::where("id", $clientId)->get()->all();
        } else {
            $clients = \App\Model\Master\Client::all();
        }
    
        foreach ($clients as $client) {
            $this->info("Processing Drip Campaign Status for Client ID: {$client->id}");
            try {
                $connection = "mysql_{$client->id}";
    
                // Fetch the records based on the schedule type (daily or weekly)
                $records = DripCampaignSchedule::on($connection)
                    ->whereIn('status', [4, 5])
                    ->where('run_time', '<=', DB::raw('NOW()'))
                    ->get()
                    ->all();
                foreach ($records as $record) {
                    $currentDay = Carbon::now()->format('l'); // Get today's full day name (e.g., Monday, Tuesday)
                    $scheduleDay = $record->schedule_day; // Get the schedule day (e.g., Monday, Tuesday)
                    $this->info("Current Day: {$currentDay}, Schedule Day: {$scheduleDay}");
                    if (!empty($record)) {
                        // Check the schedule type (daily or weekly)
                        if ($record->schedule === 'daily') {
                            // For daily schedules, use the query as is
                            $shouldRun = true;
                        } elseif ($record->schedule === 'weekly') {
                            // For weekly schedules, check if today matches the schedule day
                            $shouldRun = DB::connection($connection)
                            ->table('drip_campaign_schedules')
                            ->whereRaw('LOWER(schedule_day) = ?', [strtolower($currentDay)])
                            ->exists();
                    }                        
                        // Proceed only if the schedule is ready to run
                        if ($shouldRun) {
                            $recordSentCount = DripCampaignRuns::on($connection)
                                ->where('status', '=', 3)
                                ->where('schedule_id', $record->id)
                                ->count();
    
                            $recordFailedCount = DripCampaignRuns::on($connection)
                                ->whereIn('status', [5, 6])
                                ->where('schedule_id', $record->id)
                                ->count();
    
                            if ($record->scheduled_count <= $recordSentCount + $recordFailedCount) {
                                // Update as completed
                                DB::connection($connection)->statement(
                                    "UPDATE drip_campaign_schedules 
                                     SET sent_count='$recordSentCount', failed_count='$recordFailedCount', complete_time=UTC_TIMESTAMP(), status=6 
                                     WHERE id='{$record->id}'"
                                );
                            } elseif ($recordSentCount + $recordFailedCount > 0) {
                                // Update as partially completed
                                DB::connection($connection)->statement(
                                    "UPDATE drip_campaign_schedules 
                                     SET sent_count='$recordSentCount', failed_count='$recordFailedCount', status=5 
                                     WHERE id='{$record->id}'"
                                );
                            }
                        }
                    }
                }
            } catch (\Throwable $throwable) {
                // Log errors and show in console
                Log::error("DripCampaignScheduleStatus.error", buildContext($throwable, [
                    "clientId" => $client->id
                ]));
                $this->error("Client ID {$client->id} | Error: " . $throwable->getMessage());
            }
        }
    }
    
}
