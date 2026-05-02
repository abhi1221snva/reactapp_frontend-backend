<?php

namespace App\Console\Commands;

use App\Model\Master\Client;
use App\Model\Master\SubscriptionPlan;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AssignDefaultPlans extends Command
{
    protected $signature = 'subscription:assign-defaults';
    protected $description = 'Assign the Starter plan to all clients that have no subscription plan set';

    public function handle()
    {
        $plan = SubscriptionPlan::where('slug', SubscriptionPlan::SLUG_STARTER)->first();

        if (!$plan) {
            $this->error('Starter plan not found. Run the subscription_plans migration first.');
            return 1;
        }

        $count = DB::connection('master')
            ->table('clients')
            ->whereNull('subscription_plan_id')
            ->update([
                'subscription_plan_id'    => $plan->id,
                'subscription_status'     => 'active',
                'billing_cycle'           => 'monthly',
                'subscription_started_at' => DB::raw('created_at'),
            ]);

        $this->info("Assigned Starter plan to {$count} clients.");

        return 0;
    }
}
