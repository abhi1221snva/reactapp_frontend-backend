<?php

namespace App\Http\Controllers;

use App\Model\Master\Client;
use App\Model\Master\SubscriptionPlan;
use App\Services\PlanService;
use App\Services\StripeSubscriptionService;
use App\Services\WalletTopUpService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * Client-facing billing endpoints.
 *
 * Handles plan overview, subscription creation/upgrade, wallet top-up,
 * payment method management, and invoice listing.
 */
class BillingController extends Controller
{
    // ═══════════════════════════════════════════════════════════════════════
    //  Overview
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * GET /billing/overview
     *
     * Returns current plan, usage, wallet balance, and upcoming invoice.
     */
    public function overview(Request $request)
    {
        $clientId = $this->tenantId($request);
        $client   = Client::with('subscriptionPlan')->find($clientId);

        $planData  = PlanService::getClientPlan($clientId);
        $usage     = PlanService::getUsageSummary($clientId);
        $balance   = WalletTopUpService::getBalance($clientId);
        $upcoming  = StripeSubscriptionService::getUpcomingInvoice($clientId);

        return $this->successResponse('OK', [
            'plan'                      => $planData ? $planData['plan'] : null,
            'billing_cycle'             => $client->billing_cycle ?? 'monthly',
            'subscription_status'       => $client->subscription_status ?? null,
            'subscription_started_at'   => $client->subscription_started_at,
            'subscription_ends_at'      => $client->subscription_ends_at,
            'usage'                     => $usage,
            'wallet_balance'            => $balance,
            'wallet_low_threshold_cents' => $client->wallet_low_threshold_cents ?? 200,
            'upcoming_invoice'          => $upcoming,
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Plans
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * GET /billing/plans
     *
     * Returns available plans for comparison + current plan ID.
     */
    public function availablePlans(Request $request)
    {
        $clientId = $this->tenantId($request);
        $client   = Client::find($clientId);

        $plans = SubscriptionPlan::where('is_active', true)
            ->orderBy('display_order')
            ->get();

        return $this->successResponse('OK', [
            'plans'           => $plans->toArray(),
            'current_plan_id' => $client->subscription_plan_id,
            'billing_cycle'   => $client->billing_cycle ?? 'monthly',
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Subscribe (trial → paid)
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * POST /billing/subscribe
     *
     * Creates a Stripe subscription for the first time.
     * Only allowed when client is in trial or expired status.
     */
    public function subscribe(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'plan_id'        => 'required|integer|exists:master.subscription_plans,id',
            'payment_method' => 'required|string',
            'billing_cycle'  => 'required|in:monthly,annual',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $clientId = $this->tenantId($request);
        $client   = Client::find($clientId);

        // Only allow subscribing from trial or expired status
        if ($client->stripe_subscription_id && in_array($client->subscription_status, ['active', 'past_due'])) {
            return $this->failResponse('You already have an active subscription. Use upgrade instead.', [], null, 400);
        }

        try {
            $result = StripeSubscriptionService::createSubscription(
                $clientId,
                $request->input('plan_id'),
                $request->input('payment_method'),
                $request->input('billing_cycle')
            );

            return $this->successResponse('Subscription created', $result);
        } catch (\Throwable $e) {
            Log::error('BillingController: subscribe failed', [
                'client_id' => $clientId,
                'error'     => $e->getMessage(),
            ]);
            return $this->failResponse('Failed to create subscription: ' . $e->getMessage(), [], null, 400);
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Upgrade
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * POST /billing/upgrade
     *
     * Upgrade to a higher plan. Validates upgrade-only (no downgrade).
     */
    public function upgrade(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'plan_id'       => 'required|integer|exists:master.subscription_plans,id',
            'billing_cycle' => 'nullable|in:monthly,annual',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $clientId = $this->tenantId($request);
        $client   = Client::find($clientId);

        if (!$client->stripe_subscription_id) {
            return $this->failResponse('No active subscription to upgrade. Use subscribe first.', [], null, 400);
        }

        // Enforce upgrade-only: new plan must have higher display_order
        $currentPlan = SubscriptionPlan::find($client->subscription_plan_id);
        $newPlan     = SubscriptionPlan::find($request->input('plan_id'));

        if ($currentPlan && $newPlan && $newPlan->display_order <= $currentPlan->display_order) {
            return $this->failResponse('You can only upgrade to a higher plan. Contact support to downgrade.', [], null, 400);
        }

        try {
            $result = StripeSubscriptionService::upgradeSubscription(
                $clientId,
                $request->input('plan_id'),
                $request->input('billing_cycle')
            );

            return $this->successResponse('Plan upgraded', $result);
        } catch (\Throwable $e) {
            Log::error('BillingController: upgrade failed', [
                'client_id' => $clientId,
                'error'     => $e->getMessage(),
            ]);
            return $this->failResponse('Failed to upgrade plan: ' . $e->getMessage(), [], null, 400);
        }
    }

    /**
     * GET /billing/upgrade/preview
     *
     * Preview proration for an upgrade.
     */
    public function upgradePreview(Request $request)
    {
        $clientId = $this->tenantId($request);
        $client   = Client::find($clientId);

        if (!$client->stripe_customer_id || !$client->stripe_subscription_id) {
            return $this->successResponse('No active subscription', ['preview' => null]);
        }

        $newPlanId    = $request->input('plan_id');
        $billingCycle = $request->input('billing_cycle', $client->billing_cycle);

        $newPlan = SubscriptionPlan::find($newPlanId);
        if (!$newPlan) {
            return $this->failResponse('Plan not found', [], null, 404);
        }

        $stripePriceId = $billingCycle === 'annual'
            ? $newPlan->stripe_price_annual_id
            : $newPlan->stripe_price_monthly_id;

        if (!$stripePriceId) {
            return $this->failResponse('Plan has no Stripe price for this billing cycle', [], null, 400);
        }

        try {
            $stripe = new \Stripe\StripeClient(env('STRIPE_SECRET'));

            $subscription = $stripe->subscriptions->retrieve($client->stripe_subscription_id);
            $itemId = $subscription->items->data[0]->id;

            $preview = $stripe->invoices->upcoming([
                'customer'               => $client->stripe_customer_id,
                'subscription'           => $client->stripe_subscription_id,
                'subscription_items'     => [[
                    'id'    => $itemId,
                    'price' => $stripePriceId,
                ]],
                'subscription_proration_behavior' => 'create_prorations',
            ]);

            $lines = [];
            foreach ($preview->lines->data as $line) {
                $lines[] = [
                    'description' => $line->description,
                    'amount'      => $line->amount,
                ];
            }

            return $this->successResponse('OK', [
                'preview' => [
                    'amount_due'   => $preview->amount_due,
                    'currency'     => $preview->currency,
                    'period_start' => $preview->period_start,
                    'period_end'   => $preview->period_end,
                    'lines'        => $lines,
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->failResponse('Failed to preview upgrade: ' . $e->getMessage(), [], null, 400);
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Invoices
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * GET /billing/invoices
     */
    public function invoices(Request $request)
    {
        $clientId = $this->tenantId($request);
        $limit    = min((int) $request->input('limit', 20), 50);
        $after    = $request->input('starting_after');

        try {
            $result = StripeSubscriptionService::listInvoices($clientId, $limit, $after);
            return $this->successResponse('OK', $result);
        } catch (\Throwable $e) {
            return $this->failResponse('Failed to fetch invoices: ' . $e->getMessage(), [], null, 400);
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Wallet
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * POST /billing/wallet/top-up
     */
    public function walletTopUp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'amount'         => 'required|numeric|min:10',
            'payment_method' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $clientId = $this->tenantId($request);
        $userId   = $request->auth->id;

        try {
            $result = WalletTopUpService::topUp(
                $clientId,
                (float) $request->input('amount'),
                $request->input('payment_method'),
                $userId
            );

            return $this->successResponse('Wallet topped up', $result);
        } catch (\InvalidArgumentException $e) {
            return $this->failResponse($e->getMessage(), [], null, 422);
        } catch (\Throwable $e) {
            Log::error('BillingController: wallet top-up failed', [
                'client_id' => $clientId,
                'error'     => $e->getMessage(),
            ]);
            return $this->failResponse('Payment failed: ' . $e->getMessage(), [], null, 400);
        }
    }

    /**
     * GET /billing/wallet
     */
    public function walletBalance(Request $request)
    {
        $clientId = $this->tenantId($request);
        $balance  = WalletTopUpService::getBalance($clientId);

        return $this->successResponse('OK', [
            'balance'  => $balance,
            'currency' => 'USD',
        ]);
    }

    /**
     * GET /billing/wallet/transactions
     */
    public function walletTransactions(Request $request)
    {
        $clientId = $this->tenantId($request);
        $page     = max(1, (int) $request->input('page', 1));
        $perPage  = min(50, max(1, (int) $request->input('per_page', 25)));

        $result = WalletTopUpService::getTransactions($clientId, $page, $perPage);

        return $this->successResponse('OK', $result);
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Payment Methods
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * GET /billing/payment-methods
     */
    public function listPaymentMethods(Request $request)
    {
        $clientId = $this->tenantId($request);

        try {
            $methods = StripeSubscriptionService::listPaymentMethods($clientId);
            return $this->successResponse('OK', $methods);
        } catch (\Throwable $e) {
            return $this->failResponse('Failed to fetch payment methods: ' . $e->getMessage(), [], null, 400);
        }
    }

    /**
     * POST /billing/payment-methods
     */
    public function addPaymentMethod(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'payment_method' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $clientId = $this->tenantId($request);

        try {
            $result = StripeSubscriptionService::addPaymentMethod(
                $clientId,
                $request->input('payment_method'),
                (bool) $request->input('set_default', true)
            );

            return $this->successResponse('Payment method added', $result);
        } catch (\Throwable $e) {
            return $this->failResponse('Failed to add payment method: ' . $e->getMessage(), [], null, 400);
        }
    }

    /**
     * DELETE /billing/payment-methods/{id}
     */
    public function removePaymentMethod(Request $request, string $id)
    {
        $clientId = $this->tenantId($request);

        try {
            StripeSubscriptionService::removePaymentMethod($clientId, $id);
            return $this->successResponse('Payment method removed');
        } catch (\RuntimeException $e) {
            return $this->failResponse($e->getMessage(), [], null, 400);
        } catch (\Throwable $e) {
            return $this->failResponse('Failed to remove payment method: ' . $e->getMessage(), [], null, 400);
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Subscription Events
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * GET /billing/events
     *
     * Returns subscription lifecycle events for this client.
     */
    public function events(Request $request)
    {
        $clientId = $this->tenantId($request);
        $page     = max(1, (int) $request->input('page', 1));
        $perPage  = min(50, max(1, (int) $request->input('per_page', 25)));
        $offset   = ($page - 1) * $perPage;

        $query = DB::connection('master')
            ->table('subscription_events')
            ->where('client_id', $clientId);

        $total = $query->count();

        $events = $query
            ->orderBy('created_at', 'desc')
            ->offset($offset)
            ->limit($perPage)
            ->get();

        return $this->successResponse('OK', [
            'data'      => $events->toArray(),
            'total'     => $total,
            'page'      => $page,
            'per_page'  => $perPage,
            'last_page' => (int) ceil($total / $perPage),
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Wallet Threshold
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * PUT /billing/wallet/threshold
     *
     * Update the low-balance notification threshold.
     */
    public function updateWalletThreshold(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'wallet_low_threshold_cents' => 'required|integer|min:0|max:100000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $clientId       = $this->tenantId($request);
        $thresholdCents = (int) $request->input('wallet_low_threshold_cents');

        Client::where('id', $clientId)->update([
            'wallet_low_threshold_cents' => $thresholdCents,
            'wallet_low_notified'        => false, // reset so notification re-fires if still low
        ]);

        return $this->successResponse('Threshold updated', [
            'wallet_low_threshold_cents' => $thresholdCents,
        ]);
    }
}
