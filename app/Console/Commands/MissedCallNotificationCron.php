<?php

namespace App\Console\Commands;

use App\Model\Client\ListHeader;
use App\Model\Client\Notification;
use App\Services\PusherService;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MissedCallNotificationCron extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:missed-call-notification';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Detect and notify missed inbound calls from inbound_call_popup table after timeout';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        // Popups older than 2 minutes are considered "missed" if not confirmed
        $expiryTime = date('Y-m-d H:i:s', strtotime('-2 minutes'));

        $popups = DB::connection('master')->table('inbound_call_popup')
            ->where('confirm', '0')
            ->where('created_at', '<', $expiryTime)
            ->get();

        if ($popups->isEmpty()) {
            return;
        }

        foreach ($popups as $popup) {
            $inbound_number = $popup->inbound_number;
            $parent_id = $popup->parent_id;

            $this->info("Processing missed call: {$inbound_number} for Client: {$parent_id}");

            // Find user by extension
            $user = DB::connection('master')->table('users')
                ->where('extension', $popup->extension)
                ->where('parent_id', $parent_id)
                ->first();

            if ($user) {
                // Try to find lead_id associated with this inbound number
                $lead_id = 0;
                try {
                    $ListHeader = ListHeader::on("mysql_" . $parent_id)->where('is_dialing', 1)->get();
                    $columns = $ListHeader->pluck('column_name')->unique()->toArray();

                    if (!empty($columns)) {
                        $implode = implode(',', $columns);
                        // Match numbers with or without '+' prefix for robustness
                        $num1 = ltrim($inbound_number, '+');
                        $num2 = '+' . $num1;
                        
                        $listData = DB::connection('mysql_' . $parent_id)
                            ->selectOne("select id from list_data where '{$num1}' IN({$implode}) OR '{$num2}' IN({$implode})");
                        if ($listData) {
                            $lead_id = $listData->id;
                        }
                    }

                    // Record persistent notification
                    if ($lead_id > 0) {
                        try {
                            $notification = new Notification();
                            $notification->setConnection("mysql_$parent_id");
                            $notification->user_id = $user->id;
                            $notification->lead_id = $lead_id;
                            $notification->message = "Missed call from " . $inbound_number;
                            $notification->type = '0'; // updates/system
                            $notification->save();
                        } catch (\Exception $saveEx) {
                            Log::error('Failed to save persistent missed call notification', [
                                'error' => $saveEx->getMessage(),
                                'popup_id' => $popup->id
                            ]);
                        }
                    }

                    // Pusher Real-time Notification
                    $request = new Request();
                    $request->merge([
                        'pusher_uuid' => $user->pusher_uuid ?? null,
                        'parent_id' => $parent_id
                    ]);

                    PusherService::notify($request, [
                        'id'      => 'missed_call',
                        'name'    => 'Missed Call',
                        'type'    => 'call',
                        'module'  => 'dialer',
                        'message' => 'Missed call from ' . $inbound_number,
                        'data'    => [
                            'number' => $inbound_number,
                            'extension' => $popup->extension,
                            'lead_id' => $lead_id,
                            'time' => date('Y-m-d H:i:s')
                        ]
                    ]);
                } catch (\Exception $e) {
                    Log::error('MissedCallNotificationCron failed for popup ID ' . $popup->id, [
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // Cleanup: Delete the processed popup record
            DB::connection('master')->table('inbound_call_popup')->where('id', $popup->id)->delete();
        }

        $this->info("Completed missed call detection.");
    }
}
