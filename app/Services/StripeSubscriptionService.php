<?php

namespace App\Services;

use App\Model\Master\Client;
use App\Model\Master\ClientPackage;
use App\Model\Master\Invoice;
use App\Model\Master\Package;
use App\Model\Master\SubscriptionPlan;
use App\Model\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * StripeSubscriptionService
 *
 * Manages Stripe Subscriptions using a per-seat (quantity-based) pricing model.
 * Single plan at $29/seat/month. Stripe handles total calculation via quantity.
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
    //  Per-seat plan synchronization to Stripe
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Sync the single per-seat plan to Stripe as a Product + per-unit monthly Price.
     * Safe to run multiple times — creates new Price only if amount changed.
     */
    public static function syncPerSeatPlan(): array
    {
        $stripe = self::stripe();
        $plan   = SubscriptionPlan::getPerSeatPlan();

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
                'metadata'    => ['plan_id' => $plan->id, 'slug' => $plan->slug, 'billing_model' => 'per_seat'],
            ]);
            $plan->stripe_product_id = $product->id;
        }

        // 2. Create or update the per-unit monthly Price
        $unitAmountCents = $plan->unit_price_cents; // 2900
        $needsNewPrice   = !$plan->stripe_price_monthly_id;

        if (!$needsNewPrice) {
            try {
                $existing = $stripe->prices->retrieve($plan->stripe_price_monthly_id);
                if ($existing->unit_amount !== $unitAmountCents) {
                    $stripe->prices->update($plan->stripe_price_monthly_id, ['active' => false]);
                    $needsNewPrice = true;
                }
            } catch (\Stripe\Exception\InvalidRequestException $e) {
                $needsNewPrice = true;
            }
        }

        if ($needsNewPrice) {
            $price = $stripe->prices->create([
                'product'        => $plan->stripe_product_id,
                'unit_amount'    => $unitAmountCents,
                'currency'       => 'usd',
                'recurring'      => ['interval' => 'month'],
                'billing_scheme' => 'per_unit',
                'metadata'       => ['plan_id' => $plan->id, 'type' => 'per_seat'],
            ]);
            $plan->stripe_price_monthly_id = $price->id;
        }

        $plan->save();

        // Clear cached plan
        Cache::forget('per_seat_plan');

        Log::info('StripeSubscriptionService: per-seat plan synced to Stripe', [
            'plan_id'    => $plan->id,
            'product_id' => $plan->stripe_product_id,
            'price_id'   => $plan->stripe_price_monthly_id,
            'unit_amount' => $unitAmountCents,
        ]);

        return $plan->toArray();
    }

    /**
     * @deprecated Use syncPerSeatPlan() instead. Multi-plan sync is no longer supported.
     */
    public static function syncPlansToStripe(): array
    {
        return [self::syncPerSeatPlan()];
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Subscription lifecycle — per-seat model
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Create a new Stripe Subscription with per-seat quantity.
     * Used when a trial user subscribes for the first time.
     *
     * @param int    $clientId       The client (tenant) ID
     * @param int    $seatQuantity   Number of seats (users) to subscribe for
     * @param string $paymentMethodId Stripe payment method (pm_*)
     */
    public static function createSubscription(
        int    $clientId,
        int    $seatQuantity,
        string $paymentMethodId
    ): array {
        $client = Client::find($clientId);
        if (!$client) {
            throw new \RuntimeException("Client {$clientId} not found");
        }

        $plan = SubscriptionPlan::getPerSeatPlan();
        $stripePriceId = $plan->stripe_price_monthly_id;

        if (!$stripePriceId) {
            throw new \RuntimeException('Per-seat plan has no Stripe price. Run syncPerSeatPlan() first.');
        }

        if ($seatQuantity < 1) {
            throw new \InvalidArgumentException('Seat quantity must be at least 1');
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

        // Create subscription with quantity (Stripe calculates total = unit_amount × quantity)
        $subscription = $stripe->subscriptions->create([
            'customer'               => $stripeCustomerId,
            'items'                  => [['price' => $stripePriceId, 'quantity' => $seatQuantity]],
            'default_payment_method' => $paymentMethodId,
            'metadata'               => ['client_id' => $clientId, 'seats' => $seatQuantity],
            'expand'                 => ['latest_invoice.payment_intent'],
        ]);

        // Update client record
        $previousStatus = $client->subscription_status;
        $client->update([
            'subscription_plan_id'    => $plan->id,
            'stripe_subscription_id'  => $subscription->id,
            'stripe_price_id'         => $stripePriceId,
            'billing_cycle'           => 'monthly',
            'seat_quantity'           => $seatQuantity,
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
        self::logEvent($clientId, 'subscribed', $previousStatus ?? 'trial', 'active', $plan->id, [
            'seat_quantity'   => $seatQuantity,
            'subscription_id' => $subscription->id,
            'monthly_total'   => $seatQuantity * ($plan->unit_price_cents / 100),
        ]);

        Log::info('StripeSubscriptionService: per-seat subscription created', [
            'client_id'       => $clientId,
            'seats'           => $seatQuantity,
            'subscription_id' => $subscription->id,
            'status'          => $subscription->status,
        ]);

        return [
            'subscription_id'    => $subscription->id,
            'status'             => $subscription->status,
            'seat_quantity'      => $seatQuantity,
            'monthly_total'      => $seatQuantity * ($plan->unit_price_cents / 100),
            'current_period_end' => $subscription->current_period_end,
        ];
    }

    /**
     * Update the seat count on an existing Stripe subscription.
     *
     * Increase: immediate proration (client is charged prorated amount now).
     * Decrease: no proration (takes effect at next billing cycle).
     *
     * @param int    $clientId          The client ID
     * @param int    $newQuantity       New seat count
     * @param string $prorationBehavior 'create_prorations' or 'none'
     */
    public static function updateSeatCount(
        int    $clientId,
        int    $newQuantity,
        string $prorationBehavior = 'create_prorations'
    ): array {
        $client = Client::find($clientId);
        if (!$client || !$client->stripe_subscription_id) {
            throw new \RuntimeException("Client {$clientId} has no active Stripe subscription");
        }

        if ($newQuantity < 1) {
            throw new \InvalidArgumentException('Seat quantity must be at least 1');
        }

        $oldQuantity = (int) ($client->seat_quantity ?? 1);
        $stripe = self::stripe();

        $subscription = $stripe->subscriptions->retrieve($client->stripe_subscription_id);
        $itemId = $subscription->items->data[0]->id;

        // Increase → prorate immediately; Decrease → apply at next cycle
        $behavior = $newQuantity > $oldQuantity ? 'create_prorations' : $prorationBehavior;

        $updated = $stripe->subscriptions->update($client->stripe_subscription_id, [
            'items' => [[
                'id'       => $itemId,
                'quantity' => $newQuantity,
            ]],
            'proration_behavior' => $behavior,
            'metadata'           => ['seats' => $newQuantity],
        ]);

        $client->update([
            'seat_quantity'       => $newQuantity,
            'subscription_ends_at' => Carbon::createFromTimestamp($updated->current_period_end),
        ]);

        PlanService::invalidateClientPlan($clientId);

        $plan = SubscriptionPlan::getPerSeatPlan();
        self::logEvent($clientId, 'seats_changed', 'active', 'active', $plan->id, [
            'old_quantity' => $oldQuantity,
            'new_quantity' => $newQuantity,
            'proration'    => $behavior,
            'monthly_total' => $newQuantity * ($plan->unit_price_cents / 100),
        ]);

        Log::info('StripeSubscriptionService: seat count updated', [
            'client_id'    => $clientId,
            'old_quantity' => $oldQuantity,
            'new_quantity' => $newQuantity,
            'proration'    => $behavior,
        ]);

        return [
            'subscription_id'    => $updated->id,
            'old_quantity'       => $oldQuantity,
            'new_quantity'       => $newQuantity,
            'monthly_total'      => $newQuantity * ($plan->unit_price_cents / 100),
            'current_period_end' => $updated->current_period_end,
        ];
    }

    /**
     * Preview the billing impact of changing the seat count.
     * Returns the upcoming invoice with proration line items.
     */
    public static function getSeatChangePreview(int $clientId, int $newQuantity): ?array
    {
        $client = Client::find($clientId);
        if (!$client || !$client->stripe_customer_id || !$client->stripe_subscription_id) {
            return null;
        }

        $stripe = self::stripe();
        $subscription = $stripe->subscriptions->retrieve($client->stripe_subscription_id);
        $itemId = $subscription->items->data[0]->id;

        try {
            $preview = $stripe->invoices->upcoming([
                'customer'           => $client->stripe_customer_id,
                'subscription'       => $client->stripe_subscription_id,
                'subscription_items' => [['id' => $itemId, 'quantity' => $newQuantity]],
                'subscription_proration_behavior' => 'create_prorations',
            ]);

            $lines = [];
            foreach ($preview->lines->data as $line) {
                $lines[] = [
                    'description' => $line->description,
                    'amount'      => $line->amount,
                ];
            }

            return [
                'amount_due'   => $preview->amount_due,
                'currency'     => $preview->currency,
                'period_start' => $preview->period_start,
                'period_end'   => $preview->period_end,
                'lines'        => $lines,
            ];
        } catch (\Stripe\Exception\InvalidRequestException $e) {
            Log::warning('StripeSubscriptionService: seat preview failed', [
                'client_id' => $clientId,
                'error'     => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * @deprecated Plan upgrades are no longer supported. Use updateSeatCount() instead.
     */
    public static function upgradeSubscription(int $clientId, int $newPlanId, ?string $billingCycle = null): array
    {
        throw new \RuntimeException('Plan upgrades are no longer supported. Use updateSeatCount() to adjust seats.');
    }

    /**
     * Cancel the client's subscription at the end of the current period.
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
            'subscription_id'      => $updated->id,
            'cancel_at_period_end' => true,
            'cancel_at'            => $updated->cancel_at,
        ];
    }

    /**
     * Resume a cancelled subscription (undo cancel_at_period_end).
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
            'subscription_id'      => $updated->id,
            'status'               => $updated->status,
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
                    'client_id'              => $clientId,
                    'stripe_subscription_id' => $inv->subscription,
                    'type'                   => $inv->subscription ? 'subscription' : 'wallet_topup',
                    'status'                 => $inv->status,
                    'amount_due'             => $inv->amount_due,
                    'amount_paid'            => $inv->amount_paid,
                    'currency'               => $inv->currency,
                    'hosted_invoice_url'     => $inv->hosted_invoice_url,
                    'invoice_pdf_url'        => $inv->invoice_pdf,
                    'period_start'           => $inv->period_start ? Carbon::createFromTimestamp($inv->period_start) : null,
                    'period_end'             => $inv->period_end ? Carbon::createFromTimestamp($inv->period_end) : null,
                    'paid_at'                => $inv->status === 'paid' && $inv->status_transitions?->paid_at
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
     * Attach a payment method to the client's customer.
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
     * Remove a payment method. Blocks removal of the last card if subscription active.
     */
    public static function removePaymentMethod(int $clientId, string $paymentMethodId): void
    {
        $client = Client::find($clientId);

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
        return SubscriptionPlan::where('stripe_price_monthly_id', $stripePriceId)->first();
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
            Log::warning('StripeSubscriptionService: event logging failed', [
                'client_id'  => $clientId,
                'event_type' => $eventType,
                'error'      => $e->getMessage(),
            ]);
        }
    }
}
