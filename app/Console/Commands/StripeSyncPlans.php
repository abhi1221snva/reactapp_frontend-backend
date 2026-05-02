<?php

namespace App\Console\Commands;

use App\Services\StripeSubscriptionService;
use Illuminate\Console\Command;

class StripeSyncPlans extends Command
{
    protected $signature = 'stripe:sync-plans';
    protected $description = 'Create/update Stripe Products and Prices for all active subscription plans';

    public function handle()
    {
        $this->info('Syncing subscription plans to Stripe...');

        try {
            $results = StripeSubscriptionService::syncPlansToStripe();

            foreach ($results as $plan) {
                $this->line(sprintf(
                    '  [%s] %s — product=%s, monthly=%s, annual=%s',
                    $plan['slug'],
                    $plan['name'],
                    $plan['stripe_product_id'] ?? 'N/A',
                    $plan['stripe_price_monthly_id'] ?? 'N/A',
                    $plan['stripe_price_annual_id'] ?? 'N/A'
                ));
            }

            $this->info(count($results) . ' plans synced successfully.');
        } catch (\Throwable $e) {
            $this->error('Failed to sync plans: ' . $e->getMessage());
            return 1;
        }

        return 0;
    }
}
