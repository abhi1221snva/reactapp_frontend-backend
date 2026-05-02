<?php

namespace App\Console\Commands;

use App\Model\Master\Client;
use App\Model\Master\ClientUsageMonthly;
use App\Model\User;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class FlushUsageCounters extends Command
{
    protected $signature = 'subscription:flush-usage';
    protected $description = 'Persist Redis usage counters (calls, SMS) to the client_usage_monthly table';

    public function handle()
    {
        $yearMonth = Carbon::now()->format('Y-m');
        $flushed   = 0;

        // Get all active client IDs
        $clientIds = Client::whereNotNull('subscription_plan_id')
            ->pluck('id')
            ->toArray();

        foreach ($clientIds as $clientId) {
            $callKey = "plan_usage:calls:{$clientId}:{$yearMonth}";
            $smsKey  = "plan_usage:sms:{$clientId}:{$yearMonth}";

            $calls = (int) Cache::get($callKey, 0);
            $sms   = (int) Cache::get($smsKey, 0);

            if ($calls === 0 && $sms === 0) {
                continue;
            }

            // Count current active agents for peak tracking
            $agentsPeak = User::where('parent_id', $clientId)
                ->where('is_deleted', 0)
                ->where('status', 1)
                ->count();

            try {
                $record = ClientUsageMonthly::updateOrCreate(
                    ['client_id' => $clientId, 'year_month' => $yearMonth],
                    ['calls_count' => $calls, 'sms_count' => $sms]
                );

                // Only update agents_peak if current count exceeds stored peak
                if ($agentsPeak > ($record->agents_peak ?? 0)) {
                    $record->agents_peak = $agentsPeak;
                    $record->save();
                }

                $flushed++;
            } catch (\Throwable $e) {
                Log::warning('FlushUsageCounters: failed for client', [
                    'client_id' => $clientId,
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        $this->info("Flushed usage counters for {$flushed} clients (period: {$yearMonth}).");

        return 0;
    }
}
