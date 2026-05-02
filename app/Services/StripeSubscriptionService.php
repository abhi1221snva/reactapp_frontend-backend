<?php

namespace App\Services;

use App\Model\Master\Client;
use App\Model\Master\ClientPackage;
use App\Model\Master\Invoice;
use App\Model\Master\Package;
use App\Model\Master\SubscriptionPlan;
use App\Model\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * StripeSubscriptionService
 *
 * Manages Stripe Subscriptions, Products, Prices, and Customers
 * at the client (tenant) level for the Rocket Dialer platform.
 */
class StripeSubscriptionService
{
    // ═══════════════════════════════════════════════════════════════════════
    //  Stripe client factory
    // ═══════════════════════════════════════════════════════════════════════

    private static function stripe(): \Stripe\StripeClient
    {
        return new \Stripe\StripeClient(env('STRIPE_SECRET'));
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Customer management (client-level, NOT user-level)
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Ensure a Stripe Customer exists for the given client (tenant).
     * Creates one using the company name + admin user email if missing.
     *
     * @return string stripe_customer_id
     */
    public static function ensureStripeCustomer(int $clientId): string
    {
        $client = Client::find($clientId);
        if (!$client) {
            throw new \RuntimeException("Client {$clientId} not found");
        }

        if ($client->stripe_customer_id) {
            return $client->stripe_customer_id;
        }

        // Find the admin user (role 6) for the client
        $adminUser = User::where('parent_id', $clientId)
            ->where('user_level', 6)
            ->where('is_deleted', 0)
            ->first();

        $email = $adminUser->email ?? "client-{$clientId}@rocketdialer.com";
        $name  = $client->company_name ?: "Client #{$clientId}";

        $customer = self::stripe()->customers->create([
            'name'  => $name,
            'email' => $email,
            'metadata' => [
                'client_id'    => $clientId,
                'company_name' => $name,
            ],
        ]);

        $client->stripe_customer_id = $customer->id;
        $client->save();

        return $customer->id;
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Plan synchronization to Stripe
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Sync all subscription plans to Stripe as Products + Prices.
     * Safe to run multiple times — creates new Prices only if price changed.
     *
     * @return array synced plan summaries
     */
    public static function syncPlansToStripe(): array
    {
        $stripe = self::stripe();
        $plans  = SubscriptionPlan::where('is_active', true)->get();
        $results = [];

        foreach ($plans as $plan) {
            // 1. Create or update Stripe Product
            if ($plan->stripe_product_id) {
                $stripe->products->update($plan->stripe_product_id, [
                    'name'        => $plan->name,
                    'description' => $plan->description ?: $plan->name,
                ]);
            } else {
                $product = $stripe->products->create([
                    'name'        => $plan->name,
                    'description' => $plan->description ?: $plan->name,
                    'metadata'    => ['plan_id' => $plan->id, 'slug' => $plan->slug],
                ]);
                $plan->stripe_product_id = $product->id;
            }

            // 2. Create monthly price if needed
            if ($plan->price_monthly > 0) {
                $monthlyAmountCents = (int) round($plan->price_monthly * 100);
                $needsNewMonthly = !$plan->stripe_price_monthly_id;

                if (!$needsNewMonthly) {
                    // Check if existing price matches
                    try {
                        $existing = $stripe->prices->retrieve($plan->stripe_price_monthly_id);
                        if ($existing->unit_amount !== $monthlyAmountCents) {
                            // Price changed — archive old, create new
                            $stripe->prices->update($plan->stripe_price_monthly_id, ['active' => false]);
                            $needsNewMonthly = true;
                        }
                    } catch (\Stripe\Exception\InvalidRequestException $e) {
                        $needsNewMonthly = true;
                    }
                }

                if ($needsNewMonthly) {
                    $price = $stripe->prices->create([
                        'product'     => $plan->stripe_product_id,
                        'unit_amount' => $monthlyAmountCents,
                        'currency'    => 'usd',
                        'recurring'   => ['interval' => 'month'],
                        'metadata'    => ['plan_id' => $plan->id, 'cycle' => 'monthly'],
                    ]);
                    $plan->stripe_price_monthly_id = $price->id;
                }
            }

            // 3. Create annual price if needed
            if ($plan->price_annual > 0) {
                $annualAmountCents = (int) round($plan->price_annual * 100);
                $needsNewAnnual = !$plan->stripe_price_annual_id;

                if (!$needsNewAnnual) {
                    try {
                        $existing = $stripe->prices->retrieve($plan->stripe_price_annual_id);
                        if ($existing->unit_amount !== $annualAmountCents) {
                            $stripe->prices->update($plan->stripe_price_annual_id, ['active' => false]);
                            $needsNewAnnual = true;
                        }
                    } catch (\Stripe\Exception\InvalidRequestException $e) {
                        $needsNewAnnual = true;
                    }
                }

                if ($needsNewAnnual) {
                    $price = $stripe->prices->create([
                        'product'     => $plan->stripe_product_id,
                        'unit_amount' => $annualAmountCents,
                        'currency'    => 'usd',
                        'recurring'   => ['interval' => 'year'],
                        'metadata'    => ['plan_id' => $plan->id, 'cycle' => 'annual'],
                    ]);
                    $plan->stripe_price_annual_id = $price->id;
                }
            }

            $plan->save();

            $results[] = [
                'id'                      => $plan->id,
                'name'                    => $plan->name,
                'slug'                    => $plan->slug,
                'stripe_product_id'       => $plan->stripe_product_id,
                'stripe_price_monthly_id' => $plan->stripe_price_monthly_id,
                'stripe_price_annual_id'  => $plan->stripe_price_annual_id,
            ];
        }

        return $results;
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Subscription lifecycle
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Create a new Stripe Subscription for the client.
     * Used when a trial user subscribes for the first time.
     */
    public static function createSubscription(
        int    $clientId,
        int    $planId,
        string $paymentMethodId,
        string $billingCycle = 'monthly'
    ): array {
        $client = Client::find($clientId);
        if (!$client) {
            throw new \RuntimeException("Client {$clientId} not found");
        }

        $plan = SubscriptionPlan::findOrFail($planId);
        $stripePriceId = $billingCycle === 'annual'
            ? $plan->stripe_price_annual_id
            : $plan->stripe_price_monthly_id;

        if (!$stripePriceId) {
            throw new \RuntimeException("Plan '{$plan->slug}' has no Stripe price for {$billingCycle} cycle. Run stripe:sync-plans first.");
        }

        // Ensure Stripe customer exists
        $stripeCustomerId = self::ensureStripeCustomer($clientId);
        $stripe = self::stripe();

        // Attach payment method and set as default
        $stripe->paymentMethods->attach($paymentMethodId, [
            'customer' => $stripeCustomerId,
        ]);
        $stripe->customers->update($stripeCustomerId, [
            'invoice_settings' => ['default_payment_method' => $paymentMethodId],
        ]);

        // Build subscription params
        $subParams = [
            'customer'               => $stripeCustomerId,
            'items'                  => [['price' => $stripePriceId]],
            'default_payment_method' => $paymentMethodId,
            'metadata'               => ['client_id' => $clientId, 'plan_slug' => $plan->slug],
            'expand'                 => ['latest_invoice.payment_intent'],
        ];

        // No trial carryover: when a user subscribes, billing starts immediately.
        // The user is upgrading because they see value — don't delay revenue.

        $subscription = $stripe->subscriptions->create($subParams);

        // Update client record
        $client->update([
            'subscription_plan_id'    => $plan->id,
            'stripe_subscription_id'  => $subscription->id,
            'stripe_price_id'         => $stripePriceId,
            'billing_cycle'           => $billingCycle,
            'subscription_status'     => self::mapStripeStatus($subscription->status),
            'subscription_started_at' => $client->subscription_started_at ?: Carbon::now(),
            'subscription_ends_at'    => Carbon::createFromTimestamp($subscription->current_period_end),
        ]);

        // Expire the trial client_package so the trial banner stops showing
        ClientPackage::where('client_id', $clientId)
            ->where('package_key', Package::TRIAL_PACKAGE_KEY)
            ->where('expiry_time', '>', Carbon::now())
            ->update([
                'end_time'    => Carbon::now(),
                'expiry_time' => Carbon::now(),
            ]);

        PlanService::invalidateClientPlan($clientId);
        PlanService::syncFeatureFlagsToClient($clientId);

        // Log subscription event
        self::logEvent($clientId, 'subscribed', $client->subscription_status ?? 'trial', 'active', $plan->id, [
            'billing_cycle'   => $billingCycle,
            'subscription_id' => $subscription->id,
        ]);

        Log::info('StripeSubscriptionService: subscription created', [
            'client_id'       => $clientId,
            'plan'            => $plan->slug,
            'subscription_id' => $subscription->id,
            'status'          => $subscription->status,
        ]);

        return [
            'subscription_id' => $subscription->id,
            'status'          => $subscription->status,
            'plan'            => $plan->slug,
            'billing_cycle'   => $billingCycle,
            'current_period_end' => $subscription->current_period_end,
        ];
    }

    /**
     * Upgrade the client's subscription to a higher plan.
     * Stripe handles proration automatically.
     */
    public static function upgradeSubscription(
        int     $clientId,
        int     $newPlanId,
        ?string $billingCycle = null
    ): array {
        $client = Client::find($clientId);
        if (!$client || !$client->stripe_subscription_id) {
            throw new \RuntimeException("Client {$clientId} has no active Stripe subscription");
        }

        $newPlan = SubscriptionPlan::findOrFail($newPlanId);
        $cycle   = $billingCycle ?: $client->billing_cycle;

        $stripePriceId = $cycle === 'annual'
            ? $newPlan->stripe_price_annual_id
            : $newPlan->stripe_price_monthly_id;

        if (!$stripePriceId) {
            throw new \RuntimeException("Plan '{$newPlan->slug}' has no Stripe price for {$cycle} cycle");
        }

        $stripe = self::stripe();

        // Retrieve current subscription to get the item ID
        $subscription = $stripe->subscriptions->retrieve($client->stripe_subscription_id);
        $itemId = $subscription->items->data[0]->id;

        // Update the subscription item with proration
        $updated = $stripe->subscriptions->update($client->stripe_subscription_id, [
            'items' => [[
                'id'    => $itemId,
                'price' => $stripePriceId,
            ]],
            'proration_behavior' => 'create_prorations',
            'metadata'           => ['plan_slug' => $newPlan->slug],
        ]);

        // Update client record
        $client->update([
            'subscription_plan_id' => $newPlan->id,
            'stripe_price_id'      => $stripePriceId,
            'billing_cycle'        => $cycle,
            'subscription_status'  => self::mapStripeStatus($updated->status),
            'subscription_ends_at' => Carbon::createFromTimestamp($updated->current_period_end),
        ]);

        PlanService::invalidateClientPlan($clientId);
        PlanService::syncFeatureFlagsToClient($clientId);

        self::logEvent($clientId, 'upgraded', $client->subscription_status, 'active', $newPlan->id, [
            'previous_plan_id' => $client->subscription_plan_id,
            'billing_cycle'    => $cycle,
        ]);

        Log::info('StripeSubscriptionService: subscription upgraded', [
            'client_id' => $clientId,
            'new_plan'  => $newPlan->slug,
            'cycle'     => $cycle,
        ]);

        return [
            'subscription_id' => $updated->id,
            'status'          => $updated->status,
            'plan'            => $newPlan->slug,
            'billing_cycle'   => $cycle,
            'current_period_end' => $updated->current_period_end,
        ];
    }

    /**
     * Cancel the client's subscription at the end of the current period.
     * Admin-only operation.
     */
    public static function cancelSubscription(int $clientId): array
    {
        $client = Client::find($clientId);
        if (!$client || !$client->stripe_subscription_id) {
            throw new \RuntimeException("Client {$clientId} has no active Stripe subscription");
        }

        $stripe = self::stripe();
        $updated = $stripe->subscriptions->update($client->stripe_subscription_id, [
            'cancel_at_period_end' => true,
        ]);

        $client->update(['subscription_status' => 'cancelled']);
        PlanService::invalidateClientPlan($clientId);

        self::logEvent($clientId, 'cancelled', 'active', 'cancelled', $client->subscription_plan_id, [
            'cancel_at' => $updated->cancel_at,
        ]);

        Log::info('StripeSubscriptionService: subscription cancelled', [
            'client_id' => $clientId,
            'cancel_at' => $updated->cancel_at,
        ]);

        return [
            'subscription_id'     => $updated->id,
            'cancel_at_period_end' => true,
            'cancel_at'           => $updated->cancel_at,
        ];
    }

    /**
     * Resume a cancelled subscription (undo cancel_at_period_end).
     * Admin-only operation.
     */
    public static function resumeSubscription(int $clientId): array
    {
        $client = Client::find($clientId);
        if (!$client || !$client->stripe_subscription_id) {
            throw new \RuntimeException("Client {$clientId} has no active Stripe subscription");
        }

        $stripe = self::stripe();
        $updated = $stripe->subscriptions->update($client->stripe_subscription_id, [
            'cancel_at_period_end' => false,
        ]);

        $client->update(['subscription_status' => self::mapStripeStatus($updated->status)]);
        PlanService::invalidateClientPlan($clientId);

        return [
            'subscription_id'     => $updated->id,
            'status'              => $updated->status,
            'cancel_at_period_end' => false,
        ];
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Invoice operations
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Get the upcoming invoice preview for the client.
     */
    public static function getUpcomingInvoice(int $clientId): ?array
    {
        $client = Client::find($clientId);
        if (!$client || !$client->stripe_customer_id) {
            return null;
        }

        try {
            $stripe  = self::stripe();
            $invoice = $stripe->invoices->upcoming([
                'customer' => $client->stripe_customer_id,
            ]);

            $lines = [];
            foreach ($invoice->lines->data as $line) {
                $lines[] = [
                    'description' => $line->description,
                    'amount'      => $line->amount,
                ];
            }

            return [
                'amount_due'   => $invoice->amount_due,
                'currency'     => $invoice->currency,
                'period_start' => $invoice->period_start,
                'period_end'   => $invoice->period_end,
                'lines'        => $lines,
            ];
        } catch (\Stripe\Exception\InvalidRequestException $e) {
            // No upcoming invoice (e.g., no active subscription)
            return null;
        }
    }

    /**
     * List invoices for the client, cached locally in the invoices table.
     */
    public static function listInvoices(int $clientId, int $limit = 20, ?string $startingAfter = null): array
    {
        $client = Client::find($clientId);
        if (!$client || !$client->stripe_customer_id) {
            return ['data' => [], 'has_more' => false];
        }

        $stripe = self::stripe();
        $params = [
            'customer' => $client->stripe_customer_id,
            'limit'    => $limit,
        ];
        if ($startingAfter) {
            $params['starting_after'] = $startingAfter;
        }

        $result   = $stripe->invoices->all($params);
        $invoices = [];

        foreach ($result->data as $inv) {
            // Upsert to local cache
            Invoice::updateOrCreate(
                ['stripe_invoice_id' => $inv->id],
                [
                    'client_id'               => $clientId,
                    'stripe_subscription_id'   => $inv->subscription,
                    'type'                     => $inv->subscription ? 'subscription' : 'wallet_topup',
                    'status'                   => $inv->status,
                    'amount_due'               => $inv->amount_due,
                    'amount_paid'              => $inv->amount_paid,
                    'currency'                 => $inv->currency,
                    'hosted_invoice_url'       => $inv->hosted_invoice_url,
                    'invoice_pdf_url'          => $inv->invoice_pdf,
                    'period_start'             => $inv->period_start ? Carbon::createFromTimestamp($inv->period_start) : null,
                    'period_end'               => $inv->period_end ? Carbon::createFromTimestamp($inv->period_end) : null,
                    'paid_at'                  => $inv->status === 'paid' && $inv->status_transitions?->paid_at
                        ? Carbon::createFromTimestamp($inv->status_transitions->paid_at) : null,
                ]
            );

            $invoices[] = [
                'id'                 => $inv->id,
                'status'             => $inv->status,
                'amount_due'         => $inv->amount_due,
                'amount_paid'        => $inv->amount_paid,
                'currency'           => $inv->currency,
                'hosted_invoice_url' => $inv->hosted_invoice_url,
                'invoice_pdf_url'    => $inv->invoice_pdf,
                'period_start'       => $inv->period_start,
                'period_end'         => $inv->period_end,
                'created'            => $inv->created,
            ];
        }

        return [
            'data'     => $invoices,
            'has_more' => $result->has_more,
        ];
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Payment method management (client-level)
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * List payment methods for the client's Stripe customer.
     */
    public static function listPaymentMethods(int $clientId): array
    {
        $stripeCustomerId = self::getClientStripeCustomerId($clientId);
        if (!$stripeCustomerId) {
            return [];
        }

        $stripe  = self::stripe();
        $methods = $stripe->paymentMethods->all([
            'customer' => $stripeCustomerId,
            'type'     => 'card',
        ]);

        // Get default payment method
        $customer  = $stripe->customers->retrieve($stripeCustomerId);
        $defaultPm = $customer->invoice_settings->default_payment_method ?? null;

        $result = [];
        foreach ($methods->data as $pm) {
            $result[] = [
                'id'         => $pm->id,
                'brand'      => $pm->card->brand,
                'last4'      => $pm->card->last4,
                'exp_month'  => $pm->card->exp_month,
                'exp_year'   => $pm->card->exp_year,
                'is_default' => $pm->id === $defaultPm,
            ];
        }

        return $result;
    }

    /**
     * Attach a payment method (pm_* token from Stripe.js) to the client's customer.
     */
    public static function addPaymentMethod(int $clientId, string $paymentMethodId, bool $setDefault = true): array
    {
        $stripeCustomerId = self::ensureStripeCustomer($clientId);
        $stripe = self::stripe();

        $stripe->paymentMethods->attach($paymentMethodId, [
            'customer' => $stripeCustomerId,
        ]);

        if ($setDefault) {
            $stripe->customers->update($stripeCustomerId, [
                'invoice_settings' => ['default_payment_method' => $paymentMethodId],
            ]);
        }

        $pm = $stripe->paymentMethods->retrieve($paymentMethodId);

        return [
            'id'         => $pm->id,
            'brand'      => $pm->card->brand,
            'last4'      => $pm->card->last4,
            'exp_month'  => $pm->card->exp_month,
            'exp_year'   => $pm->card->exp_year,
            'is_default' => $setDefault,
        ];
    }

    /**
     * Remove a payment method from the client's customer.
     * Blocks removal of the last card if an active subscription exists.
     */
    public static function removePaymentMethod(int $clientId, string $paymentMethodId): void
    {
        $client = Client::find($clientId);

        // Check if this is the last payment method and subscription is active
        if ($client && $client->stripe_subscription_id) {
            $methods = self::listPaymentMethods($clientId);
            if (count($methods) <= 1) {
                throw new \RuntimeException('Cannot remove the last payment method while an active subscription exists');
            }
        }

        $stripe = self::stripe();
        $stripe->paymentMethods->detach($paymentMethodId);
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Webhook helpers
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Find the client by their Stripe customer ID.
     */
    public static function findClientByStripeCustomer(string $stripeCustomerId): ?Client
    {
        return Client::where('stripe_customer_id', $stripeCustomerId)->first();
    }

    /**
     * Find the subscription plan by a Stripe price ID.
     */
    public static function findPlanByStripePrice(string $stripePriceId): ?SubscriptionPlan
    {
        return SubscriptionPlan::where('stripe_price_monthly_id', $stripePriceId)
            ->orWhere('stripe_price_annual_id', $stripePriceId)
            ->first();
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Internal helpers
    // ═══════════════════════════════════════════════════════════════════════

    private static function getClientStripeCustomerId(int $clientId): ?string
    {
        return Client::where('id', $clientId)->value('stripe_customer_id');
    }

    /**
     * Map Stripe subscription status to our internal status enum.
     */
    public static function mapStripeStatus(string $stripeStatus): string
    {
        return match ($stripeStatus) {
            'active'   => 'active',
            'trialing' => 'trial',
            'past_due' => 'past_due',
            'canceled', 'cancelled' => 'cancelled',
            'unpaid', 'incomplete', 'incomplete_expired' => 'expired',
            default => 'active',
        };
    }

    /**
     * Log a subscription lifecycle event to the subscription_events table.
     */
    public static function logEvent(
        int     $clientId,
        string  $eventType,
        ?string $fromStatus,
        string  $toStatus,
        ?int    $planId = null,
        array   $metadata = [],
        string  $triggeredBy = 'system'
    ): void {
        try {
            DB::connection('master')->table('subscription_events')->insert([
                'client_id'    => $clientId,
                'event_type'   => $eventType,
                'from_status'  => $fromStatus,
                'to_status'    => $toStatus,
                'plan_id'      => $planId,
                'metadata'     => !empty($metadata) ? json_encode($metadata) : null,
                'triggered_by' => $triggeredBy,
                'created_at'   => Carbon::now(),
            ]);
        } catch (\Throwable $e) {
            // Don't fail the operation if event logging fails
            Log::warning('StripeSubscriptionService: event logging failed', [
                'client_id'  => $clientId,
                'event_type' => $eventType,
                'error'      => $e->getMessage(),
            ]);
        }
    }
}
