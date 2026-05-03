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
     * Returns current plan, seat info, usage, wallet balance, and upcoming invoice.
     */
    public function overview(Request $request)
    {
        $clientId = $this->tenantId($request);
        $client   = Client::with('subscriptionPlan')->find($clientId);

        $planData  = PlanService::getClientPlan($clientId);
        $usage     = PlanService::getUsageSummary($clientId);
        $balance   = WalletTopUpService::getBalance($clientId);
        $upcoming  = StripeSubscriptionService::getUpcomingInvoice($clientId);

        $plan = $planData ? $planData['plan'] : null;
        $seatQuantity  = (int) ($client->seat_quantity ?? 1);
        $pricePerSeat  = $plan ? (int) ($plan['unit_price_cents'] ?? 2900) : 2900;
        $monthlyTotal  = $seatQuantity * $pricePerSeat;

        return $this->successResponse('OK', [
            'plan'                      => $plan,
            'billing_cycle'             => 'monthly',
            'subscription_status'       => $client->subscription_status ?? null,
            'subscription_started_at'   => $client->subscription_started_at,
            'subscription_ends_at'      => $client->subscription_ends_at,
            'seat_quantity'             => $seatQuantity,
            'price_per_seat'            => $pricePerSeat,
            'monthly_total'             => $monthlyTotal,
            'usage'                     => $usage,
            'wallet_balance'            => $balance,
            'wallet_low_threshold_cents' => $client->wallet_low_threshold_cents ?? 200,
            'upcoming_invoice'          => $upcoming,
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Plan info (all tiered per-seat plans)
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * GET /billing/plans
     *
     * Returns all active per-seat plans + client's current plan + seat count.
     */
    public function planInfo(Request $request)
    {
        $clientId = $this->tenantId($request);
        $client   = Client::find($clientId);

        $plans = SubscriptionPlan::getActivePlans();
        $currentPlan = $client->subscription_plan_id
            ? $plans->firstWhere('id', $client->subscription_plan_id)
            : null;

        return $this->successResponse('OK', [
            'plans'            => $plans->toArray(),
            'current_plan'     => $currentPlan ? $currentPlan->toArray() : null,
            'seat_quantity'    => (int) ($client->seat_quantity ?? 1),
            'has_subscription' => !empty($client->stripe_subscription_id),
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Subscribe (trial → paid) — per-seat
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * POST /billing/subscribe
     *
     * Creates a Stripe subscription with per-seat quantity.
     * Only allowed when client is in trial or expired status.
     */
    public function subscribe(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'plan_id'        => 'required|integer|exists:master.subscription_plans,id',
            'seat_count'     => 'required|integer|min:1',
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
        $client   = Client::find($clientId);

        // Only allow subscribing from trial or expired status
        if ($client->stripe_subscription_id && in_array($client->subscription_status, ['active', 'past_due'])) {
            return $this->failResponse('You already have an active subscription. Use change-plan or update-seats instead.', [], null, 400);
        }

        try {
            $result = StripeSubscriptionService::createSubscription(
                $clientId,
                (int) $request->input('plan_id'),
                (int) $request->input('seat_count'),
                $request->input('payment_method')
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
    //  Update Seats
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * POST /billing/update-seats
     *
     * Change the seat quantity on the Stripe subscription.
     * Increase = prorated immediately, decrease = applied at next billing cycle.
     */
    public function updateSeats(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'seat_count' => 'required|integer|min:1',
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
            return $this->failResponse('No active subscription. Use subscribe first.', [], null, 400);
        }

        $newQty    = (int) $request->input('seat_count');
        $currentQty = (int) ($client->seat_quantity ?? 1);

        // Determine proration: increase = prorate, decrease = none
        $prorationBehavior = $newQty > $currentQty ? 'create_prorations' : 'none';

        try {
            $result = StripeSubscriptionService::updateSeatCount(
                $clientId,
                $newQty,
                $prorationBehavior
            );

            return $this->successResponse('Seats updated', $result);
        } catch (\Throwable $e) {
            Log::error('BillingController: updateSeats failed', [
                'client_id'  => $clientId,
                'seat_count' => $newQty,
                'error'      => $e->getMessage(),
            ]);
            return $this->failResponse('Failed to update seats: ' . $e->getMessage(), [], null, 400);
        }
    }

    /**
     * GET /billing/seats/preview
     *
     * Preview proration for a seat count change.
     */
    public function seatsPreview(Request $request)
    {
        $clientId = $this->tenantId($request);
        $client   = Client::find($clientId);

        if (!$client->stripe_customer_id || !$client->stripe_subscription_id) {
            return $this->successResponse('No active subscription', ['preview' => null]);
        }

        $newQty = (int) $request->input('seat_count', $client->seat_quantity ?? 1);

        try {
            $preview = StripeSubscriptionService::getSeatChangePreview($clientId, $newQty);

            $plan = SubscriptionPlan::find($client->subscription_plan_id);
            return $this->successResponse('OK', [
                'preview'          => $preview,
                'current_seats'    => (int) ($client->seat_quantity ?? 1),
                'new_seats'        => $newQty,
                'price_per_seat'   => $plan ? $plan->unit_price_cents : 2900,
            ]);
        } catch (\Throwable $e) {
            return $this->failResponse('Failed to preview seat change: ' . $e->getMessage(), [], null, 400);
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Change Plan
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * POST /billing/change-plan
     *
     * Switch to a different plan tier (upgrade/downgrade).
     * Upgrades prorate immediately, downgrades apply at next cycle.
     */
    public function changePlan(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'plan_id' => 'required|integer|exists:master.subscription_plans,id',
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
            return $this->failResponse('No active subscription. Subscribe first.', [], null, 400);
        }

        $newPlanId = (int) $request->input('plan_id');
        if ($newPlanId === (int) $client->subscription_plan_id) {
            return $this->failResponse('Already on this plan.', [], null, 400);
        }

        try {
            $result = StripeSubscriptionService::changePlan($clientId, $newPlanId);
            return $this->successResponse('Plan changed', $result);
        } catch (\Throwable $e) {
            Log::error('BillingController: changePlan failed', [
                'client_id'   => $clientId,
                'new_plan_id' => $newPlanId,
                'error'       => $e->getMessage(),
            ]);
            return $this->failResponse('Failed to change plan: ' . $e->getMessage(), [], null, 400);
        }
    }

    /**
     * GET /billing/change-plan/preview?plan_id=N
     *
     * Preview proration for a plan change.
     */
    public function changePlanPreview(Request $request)
    {
        $clientId = $this->tenantId($request);
        $client   = Client::find($clientId);

        if (!$client->stripe_customer_id || !$client->stripe_subscription_id) {
            return $this->successResponse('No active subscription', ['preview' => null]);
        }

        $newPlanId = (int) $request->input('plan_id');

        try {
            $preview = StripeSubscriptionService::getPlanChangePreview($clientId, $newPlanId);
            return $this->successResponse('OK', ['preview' => $preview]);
        } catch (\Throwable $e) {
            return $this->failResponse('Failed to preview plan change: ' . $e->getMessage(), [], null, 400);
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
