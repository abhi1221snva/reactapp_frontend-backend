<?php

namespace App\Http\Controllers;

use App\Model\Master\Client;
use App\Model\Master\SubscriptionPlan;
use App\Services\PlanService;
use App\Services\StripeSubscriptionService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * Subscription plan management and client-facing plan/usage endpoints.
 */
class PlanController extends Controller
{
    // ═══════════════════════════════════════════════════════════════════════
    //  Admin endpoints (superadmin only)
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * GET /admin/subscription-plans
     *
     * List all subscription plans ordered by display_order.
     */
    public function listPlans()
    {
        $plans = SubscriptionPlan::orderBy('display_order')->get();

        return $this->successResponse('Plans retrieved', $plans->toArray());
    }

    /**
     * POST /admin/subscription-plans
     *
     * Create a new subscription plan.
     */
    public function createPlan(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'slug'              => 'required|string|max:30|unique:master.subscription_plans,slug',
            'name'              => 'required|string|max:60',
            'description'       => 'nullable|string',
            'price_monthly'     => 'required|numeric|min:0',
            'price_annual'      => 'required|numeric|min:0',
            'max_agents'        => 'required|integer|min:0',
            'max_calls_monthly' => 'required|integer|min:0',
            'max_sms_monthly'   => 'required|integer|min:0',
            'display_order'     => 'nullable|integer|min:0',
            'trial_days'        => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $data = $request->only(array_keys(array_flip((new SubscriptionPlan)->getFillable())));
        $plan = SubscriptionPlan::create($data);

        Log::info('PlanController: plan created', [
            'plan_id' => $plan->id,
            'slug'    => $plan->slug,
            'by'      => $request->auth->id,
        ]);

        return $this->successResponse('Plan created', $plan->toArray());
    }

    /**
     * PUT /admin/subscription-plans/{id}
     *
     * Update an existing subscription plan.
     */
    public function updatePlan(Request $request, int $id)
    {
        $plan = SubscriptionPlan::find($id);
        if (!$plan) {
            return $this->failResponse('Plan not found', [], null, 404);
        }

        $validator = Validator::make($request->all(), [
            'slug'              => "sometimes|required|string|max:30|unique:master.subscription_plans,slug,{$id}",
            'name'              => 'sometimes|required|string|max:60',
            'price_monthly'     => 'sometimes|numeric|min:0',
            'price_annual'      => 'sometimes|numeric|min:0',
            'max_agents'        => 'sometimes|integer|min:0',
            'max_calls_monthly' => 'sometimes|integer|min:0',
            'max_sms_monthly'   => 'sometimes|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $data = $request->only(array_keys(array_flip($plan->getFillable())));
        $plan->update($data);

        // Invalidate cache for all clients on this plan
        $affectedClients = Client::where('subscription_plan_id', $id)->pluck('id');
        foreach ($affectedClients as $clientId) {
            PlanService::invalidateClientPlan($clientId);
        }

        Log::info('PlanController: plan updated', [
            'plan_id'          => $id,
            'affected_clients' => $affectedClients->count(),
            'by'               => $request->auth->id,
        ]);

        return $this->successResponse('Plan updated', $plan->fresh()->toArray());
    }

    /**
     * GET /admin/clients/{id}/subscription
     *
     * Get subscription details and usage for a specific client.
     */
    public function getClientSubscription(int $id)
    {
        $client = Client::with('subscriptionPlan')->find($id);
        if (!$client) {
            return $this->failResponse('Client not found', [], null, 404);
        }

        $usage = PlanService::getUsageSummary($id);
        $features = PlanService::getAllFeatures($id);

        return $this->successResponse('OK', [
            'client_id'           => $id,
            'plan'                => $client->subscriptionPlan ? $client->subscriptionPlan->toArray() : null,
            'billing_cycle'       => $client->billing_cycle,
            'subscription_status' => $client->subscription_status,
            'subscription_started_at' => $client->subscription_started_at,
            'subscription_ends_at'    => $client->subscription_ends_at,
            'custom_max_agents'        => $client->custom_max_agents,
            'custom_max_calls_monthly' => $client->custom_max_calls_monthly,
            'custom_max_sms_monthly'   => $client->custom_max_sms_monthly,
            'usage'    => $usage,
            'features' => $features,
        ]);
    }

    /**
     * PUT /admin/clients/{id}/subscription
     *
     * Assign or change subscription plan for a client.
     */
    public function assignPlan(Request $request, int $id)
    {
        $client = Client::find($id);
        if (!$client) {
            return $this->failResponse('Client not found', [], null, 404);
        }

        $validator = Validator::make($request->all(), [
            'subscription_plan_id'     => 'required|integer|exists:master.subscription_plans,id',
            'billing_cycle'            => 'sometimes|in:monthly,annual',
            'subscription_status'      => 'sometimes|in:active,trial,past_due,cancelled,expired',
            'seat_quantity'            => 'nullable|integer|min:1',
            'custom_max_agents'        => 'nullable|integer|min:0',
            'custom_max_calls_monthly' => 'nullable|integer|min:0',
            'custom_max_sms_monthly'   => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $updates = [
            'subscription_plan_id' => $request->input('subscription_plan_id'),
        ];

        if ($request->has('billing_cycle')) {
            $updates['billing_cycle'] = $request->input('billing_cycle');
        }

        if ($request->has('subscription_status')) {
            $updates['subscription_status'] = $request->input('subscription_status');
        }

        // Set started_at if this is the first plan assignment
        if (!$client->subscription_started_at) {
            $updates['subscription_started_at'] = Carbon::now();
        }

        // Handle seat_quantity
        if ($request->has('seat_quantity')) {
            $updates['seat_quantity'] = (int) $request->input('seat_quantity');
        }

        // Handle custom overrides (pass null to clear)
        if ($request->has('custom_max_agents')) {
            $updates['custom_max_agents'] = $request->input('custom_max_agents');
        }
        if ($request->has('custom_max_calls_monthly')) {
            $updates['custom_max_calls_monthly'] = $request->input('custom_max_calls_monthly');
        }
        if ($request->has('custom_max_sms_monthly')) {
            $updates['custom_max_sms_monthly'] = $request->input('custom_max_sms_monthly');
        }

        $client->update($updates);

        // Invalidate cache and sync legacy feature flags
        PlanService::invalidateClientPlan($id);
        PlanService::syncFeatureFlagsToClient($id);

        Log::info('PlanController: plan assigned to client', [
            'client_id' => $id,
            'plan_id'   => $request->input('subscription_plan_id'),
            'by'        => $request->auth->id,
        ]);

        return $this->successResponse('Plan assigned', $client->fresh()->toArray());
    }

    /**
     * POST /admin/clients/{id}/subscription/cancel
     *
     * Cancel a client's Stripe subscription at period end.
     */
    public function adminCancelSubscription(Request $request, int $id)
    {
        $client = Client::find($id);
        if (!$client) {
            return $this->failResponse('Client not found', [], null, 404);
        }

        try {
            $result = StripeSubscriptionService::cancelSubscription($id);
            return $this->successResponse('Subscription cancelled at period end', $result);
        } catch (\Throwable $e) {
            return $this->failResponse('Failed to cancel: ' . $e->getMessage(), [], null, 400);
        }
    }

    /**
     * POST /admin/clients/{id}/subscription/resume
     *
     * Resume a cancelled subscription (undo cancel_at_period_end).
     */
    public function adminResumeSubscription(Request $request, int $id)
    {
        $client = Client::find($id);
        if (!$client) {
            return $this->failResponse('Client not found', [], null, 404);
        }

        try {
            $result = StripeSubscriptionService::resumeSubscription($id);
            return $this->successResponse('Subscription resumed', $result);
        } catch (\Throwable $e) {
            return $this->failResponse('Failed to resume: ' . $e->getMessage(), [], null, 400);
        }
    }

    /**
     * POST /admin/subscription-plans/sync-stripe
     *
     * Sync all active plans to Stripe (create/update Products + Prices).
     */
    public function syncToStripe(Request $request)
    {
        try {
            $result = StripeSubscriptionService::syncAllPlansToStripe();
            return $this->successResponse('All plans synced to Stripe', $result);
        } catch (\Throwable $e) {
            return $this->failResponse('Sync failed: ' . $e->getMessage(), [], null, 500);
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Client-facing endpoints
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * GET /subscription/plan
     *
     * Returns the current plan details for the authenticated client.
     */
    public function myPlan(Request $request)
    {
        $clientId = $this->tenantId($request);
        $data = PlanService::getClientPlan($clientId);

        if (!$data) {
            return $this->successResponse('No plan assigned', [
                'plan'   => null,
                'status' => null,
            ]);
        }

        return $this->successResponse('OK', [
            'plan'                    => $data['plan'],
            'billing_cycle'           => $data['client']['billing_cycle'],
            'subscription_status'     => $data['client']['subscription_status'],
            'subscription_started_at' => $data['client']['subscription_started_at'],
            'subscription_ends_at'    => $data['client']['subscription_ends_at'],
            'seat_quantity'           => (int) ($data['client']['seat_quantity'] ?? 1),
        ]);
    }

    /**
     * GET /subscription/usage
     *
     * Returns current month usage vs plan limits.
     */
    public function myUsage(Request $request)
    {
        $clientId = $this->tenantId($request);
        $usage = PlanService::getUsageSummary($clientId);

        return $this->successResponse('OK', $usage);
    }

    /**
     * GET /subscription/features
     *
     * Returns all feature flags with their enabled/disabled status.
     */
    public function myFeatures(Request $request)
    {
        $clientId = $this->tenantId($request);
        $features = PlanService::getAllFeatures($clientId);

        return $this->successResponse('OK', $features);
    }
}
