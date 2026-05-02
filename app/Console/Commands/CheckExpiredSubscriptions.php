<?php

namespace App\Console\Commands;

use App\Jobs\TrialEndingNotificationJob;
use App\Model\Master\Client;
use App\Services\PlanService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CheckExpiredSubscriptions extends Command
{
    protected $signature = 'subscription:check-expired';
    protected $description = 'Expire trials/subscriptions, set grace periods, and send warnings';

    const GRACE_DAYS = 3;

    public function handle()
    {
        $now = Carbon::now();

        $expiredCount  = $this->expireSubscriptions($now);
        $warningCount  = $this->sendTrialWarnings($now);
        $graceCount    = $this->clearExpiredGracePeriods($now);

        $this->info("Expired: {$expiredCount}, Warnings: {$warningCount}, Grace ended: {$graceCount}");

        return 0;
    }

    /**
     * Mark subscriptions as expired when their end date has passed.
     * Sets a grace period for data access.
     * Skips clients with active Stripe subscriptions (webhooks manage those).
     */
    private function expireSubscriptions(Carbon $now): int
    {
        $expired = DB::connection('master')
            ->table('clients')
            ->whereNotNull('subscription_ends_at')
            ->where('subscription_ends_at', '<', $now)
            ->whereIn('subscription_status', ['active', 'trial'])
            ->whereNull('stripe_subscription_id')
            ->where('is_deleted', 0)
            ->get(['id', 'subscription_status', 'subscription_plan_id', 'subscription_ends_at']);

        $count = 0;

        foreach ($expired as $client) {
            $graceEnd = $now->copy()->addDays(self::GRACE_DAYS);

            DB::connection('master')
                ->table('clients')
                ->where('id', $client->id)
                ->update([
                    'subscription_status'  => 'expired',
                    'grace_period_ends_at' => $graceEnd,
                ]);

            // Log the subscription event
            $eventType = $client->subscription_status === 'trial'
                ? 'trial_expired'
                : 'subscription_expired';

            DB::connection('master')->table('subscription_events')->insert([
                'client_id'    => $client->id,
                'event_type'   => $eventType,
                'from_status'  => $client->subscription_status,
                'to_status'    => 'expired',
                'plan_id'      => $client->subscription_plan_id,
                'metadata'     => json_encode([
                    'ended_at'        => $client->subscription_ends_at,
                    'grace_ends_at'   => $graceEnd->toDateTimeString(),
                ]),
                'triggered_by' => 'scheduler',
                'created_at'   => $now,
            ]);

            PlanService::invalidateClientPlan($client->id);
            $count++;

            Log::info('CheckExpiredSubscriptions: client expired', [
                'client_id'       => $client->id,
                'previous_status' => $client->subscription_status,
                'ended_at'        => $client->subscription_ends_at,
                'grace_ends_at'   => $graceEnd->toDateTimeString(),
            ]);
        }

        return $count;
    }

    /**
     * Warn trial clients 3 days before expiry.
     */
    private function sendTrialWarnings(Carbon $now): int
    {
        $warningDate = $now->copy()->addDays(self::GRACE_DAYS);

        $clients = DB::connection('master')
            ->table('clients')
            ->where('subscription_status', 'trial')
            ->whereBetween('subscription_ends_at', [$now, $warningDate])
            ->where('is_deleted', 0)
            ->get(['id', 'company_name', 'subscription_ends_at']);

        foreach ($clients as $client) {
            Log::info('CheckExpiredSubscriptions: trial ending soon', [
                'client_id'    => $client->id,
                'company_name' => $client->company_name,
                'ends_at'      => $client->subscription_ends_at,
            ]);
            dispatch(new TrialEndingNotificationJob($client->id));
        }

        return $clients->count();
    }

    /**
     * Clear grace_period_ends_at for clients whose grace period has ended.
     */
    private function clearExpiredGracePeriods(Carbon $now): int
    {
        $graceExpired = DB::connection('master')
            ->table('clients')
            ->where('subscription_status', 'expired')
            ->whereNotNull('grace_period_ends_at')
            ->where('grace_period_ends_at', '<', $now)
            ->where('is_deleted', 0)
            ->get(['id', 'subscription_plan_id']);

        foreach ($graceExpired as $client) {
            DB::connection('master')
                ->table('clients')
                ->where('id', $client->id)
                ->update(['grace_period_ends_at' => null]);

            DB::connection('master')->table('subscription_events')->insert([
                'client_id'    => $client->id,
                'event_type'   => 'grace_ended',
                'from_status'  => 'expired',
                'to_status'    => 'expired',
                'plan_id'      => $client->subscription_plan_id,
                'triggered_by' => 'scheduler',
                'created_at'   => $now,
            ]);
        }

        return $graceExpired->count();
    }
}
