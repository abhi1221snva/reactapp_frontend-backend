<?php

namespace App\Console\Commands;

use App\Model\Master\Client;
use App\Services\PlanService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CheckExpiredSubscriptions extends Command
{
    protected $signature = 'subscription:check-expired';
    protected $description = 'Mark subscriptions as expired when their end date has passed';

    public function handle()
    {
        $now = Carbon::now();

        // Skip clients with active Stripe subscriptions — webhooks manage their status
        $expired = DB::connection('master')
            ->table('clients')
            ->whereNotNull('subscription_ends_at')
            ->where('subscription_ends_at', '<', $now)
            ->whereIn('subscription_status', ['active', 'trial'])
            ->whereNull('stripe_subscription_id')
            ->get(['id', 'subscription_status', 'subscription_ends_at']);

        $count = 0;

        foreach ($expired as $client) {
            DB::connection('master')
                ->table('clients')
                ->where('id', $client->id)
                ->update(['subscription_status' => 'expired']);

            PlanService::invalidateClientPlan($client->id);

            $count++;

            Log::info('CheckExpiredSubscriptions: client expired', [
                'client_id'      => $client->id,
                'previous_status' => $client->subscription_status,
                'ended_at'       => $client->subscription_ends_at,
            ]);
        }

        $this->info("Marked {$count} subscriptions as expired.");

        return 0;
    }
}
