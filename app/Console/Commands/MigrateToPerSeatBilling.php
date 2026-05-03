<?php

namespace App\Console\Commands;

use App\Model\Master\Client;
use App\Model\Master\SubscriptionPlan;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Migrates existing Stripe subscribers from legacy multi-tier plans
 * to the single per-seat ($29/user/month) billing model.
 *
 * For each active subscriber:
 * 1. Updates the Stripe subscription item to the new per-seat price_id
 * 2. Sets quantity = client.seat_quantity
 * 3. Uses proration_behavior = 'none' to avoid surprise charges during migration
 *
 * Usage:
 *   php artisan billing:migrate-per-seat                  # All active subscribers
 *   php artisan billing:migrate-per-seat --dry-run        # Preview only
 *   php artisan billing:migrate-per-seat --client=42      # Single client
 */
class MigrateToPerSeatBilling extends Command
{
    protected $signature = 'billing:migrate-per-seat
                            {--dry-run : Preview changes without modifying Stripe}
                            {--client= : Migrate a single client by ID}';

    protected $description = 'Migrate existing Stripe subscribers to per-seat billing';

    public function handle(): int
    {
        $dryRun   = $this->option('dry-run');
        $clientId = $this->option('client');

        if ($dryRun) {
            $this->info('🔍 DRY RUN — no Stripe modifications will be made');
        }

        // ── Load per-seat plan ────────────────────────────────────────
        $plan = SubscriptionPlan::where('slug', SubscriptionPlan::SLUG_PER_SEAT)
            ->where('is_active', true)
            ->first();

        if (!$plan) {
            $this->error('Per-seat plan not found. Run the migration first.');
            return 1;
        }

        $newPriceId = $plan->stripe_price_monthly_id;
        if (!$newPriceId) {
            $this->error('Per-seat plan has no stripe_price_monthly_id. Run syncPerSeatPlan() first.');
            return 1;
        }

        $this->info("Per-seat plan: {$plan->name} (ID: {$plan->id})");
        $this->info("Stripe price: {$newPriceId}");
        $this->info("Unit price: \${$plan->unit_price_cents / 100}/seat/month");
        $this->line('');

        // ── Query subscribers ─────────────────────────────────────────
        $query = Client::where('is_deleted', 0)
            ->whereNotNull('stripe_subscription_id')
            ->whereIn('subscription_status', ['active', 'past_due', 'trial']);

        if ($clientId) {
            $query->where('id', $clientId);
        }

        $clients = $query->get();

        if ($clients->isEmpty()) {
            $this->info('No active subscribers found to migrate.');
            return 0;
        }

        $this->info("Found {$clients->count()} subscriber(s) to migrate");
        $this->line('');

        $stripe  = new \Stripe\StripeClient(env('STRIPE_SECRET'));
        $success = 0;
        $skipped = 0;
        $failed  = 0;

        foreach ($clients as $client) {
            $this->line("─── Client #{$client->id}: {$client->company_name} ───");
            $this->line("  Stripe Sub: {$client->stripe_subscription_id}");
            $this->line("  Seats: {$client->seat_quantity}");
            $this->line("  Status: {$client->subscription_status}");

            // Already on the per-seat price?
            if ($client->stripe_price_id === $newPriceId) {
                $this->line("  ⏭ Already on per-seat price — skipping");
                $skipped++;
                continue;
            }

            if ($dryRun) {
                $this->line("  ✅ Would update: price={$newPriceId}, quantity={$client->seat_quantity}");
                $success++;
                continue;
            }

            try {
                // Fetch current subscription
                $sub = $stripe->subscriptions->retrieve($client->stripe_subscription_id);
                $itemId = $sub->items->data[0]->id;

                // Update subscription item to new price + quantity
                $stripe->subscriptions->update($client->stripe_subscription_id, [
                    'items' => [[
                        'id'       => $itemId,
                        'price'    => $newPriceId,
                        'quantity' => max(1, (int) $client->seat_quantity),
                    ]],
                    'proration_behavior' => 'none',
                ]);

                // Update local records
                $client->update([
                    'subscription_plan_id' => $plan->id,
                    'stripe_price_id'      => $newPriceId,
                    'billing_cycle'        => 'monthly',
                ]);

                $this->line("  ✅ Migrated successfully");
                $success++;

                Log::info('MigrateToPerSeatBilling: client migrated', [
                    'client_id' => $client->id,
                    'seats'     => $client->seat_quantity,
                ]);
            } catch (\Throwable $e) {
                $this->error("  ❌ Failed: {$e->getMessage()}");
                $failed++;

                Log::error('MigrateToPerSeatBilling: migration failed', [
                    'client_id' => $client->id,
                    'error'     => $e->getMessage(),
                ]);
            }

            $this->line('');
        }

        $this->line('════════════════════════════════════════════');
        $this->info("Success: {$success}  |  Skipped: {$skipped}  |  Failed: {$failed}");

        if ($dryRun) {
            $this->line('');
            $this->warn('This was a dry run. Run without --dry-run to apply changes.');
        }

        return $failed > 0 ? 1 : 0;
    }
}
