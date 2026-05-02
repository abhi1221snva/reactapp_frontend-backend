<?php

namespace App\Http\Controllers;

use App\Jobs\PaymentFailedNotificationJob;
use App\Model\Master\Client;
use App\Model\Master\Invoice;
use App\Services\PlanService;
use App\Services\StripeSubscriptionService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Handles Stripe webhook events.
 *
 * This controller is on a public route (no JWT) — authentication
 * is done via Stripe-Signature header validation.
 */
class StripeWebhookController extends Controller
{
    /**
     * POST /stripe/webhook
     */
    public function handleWebhook(Request $request)
    {
        $payload   = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');
        $secret    = env('STRIPE_WEBHOOK_SECRET');

        if (!$secret) {
            Log::error('StripeWebhook: STRIPE_WEBHOOK_SECRET not configured');
            return response('Webhook secret not configured', 500);
        }

        // Validate signature
        try {
            $event = \Stripe\Webhook::constructEvent($payload, $sigHeader, $secret);
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            Log::warning('StripeWebhook: signature verification failed', [
                'error' => $e->getMessage(),
            ]);
            return response('Invalid signature', 400);
        } catch (\UnexpectedValueException $e) {
            Log::warning('StripeWebhook: invalid payload', [
                'error' => $e->getMessage(),
            ]);
            return response('Invalid payload', 400);
        }

        // Dispatch to handler
        try {
            switch ($event->type) {
                case 'invoice.paid':
                    $this->handleInvoicePaid($event->data->object);
                    break;

                case 'invoice.payment_failed':
                    $this->handleInvoicePaymentFailed($event->data->object);
                    break;

                case 'customer.subscription.updated':
                    $this->handleSubscriptionUpdated($event->data->object);
                    break;

                case 'customer.subscription.deleted':
                    $this->handleSubscriptionDeleted($event->data->object);
                    break;

                case 'customer.subscription.trial_will_end':
                    $this->handleTrialWillEnd($event->data->object);
                    break;

                default:
                    Log::debug('StripeWebhook: unhandled event type', ['type' => $event->type]);
            }
        } catch (\Throwable $e) {
            Log::error('StripeWebhook: handler error', [
                'type'  => $event->type,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        // Always return 200 to acknowledge receipt
        return response('OK', 200);
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Event handlers
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * invoice.paid — Subscription invoice was successfully paid.
     */
    private function handleInvoicePaid(object $invoice): void
    {
        $client = StripeSubscriptionService::findClientByStripeCustomer($invoice->customer);
        if (!$client) {
            Log::warning('StripeWebhook: invoice.paid — client not found', [
                'customer' => $invoice->customer,
            ]);
            return;
        }

        // Upsert invoice record (idempotent)
        Invoice::updateOrCreate(
            ['stripe_invoice_id' => $invoice->id],
            [
                'client_id'             => $client->id,
                'stripe_subscription_id' => $invoice->subscription,
                'type'                  => $invoice->subscription ? 'subscription' : 'wallet_topup',
                'status'                => 'paid',
                'amount_due'            => $invoice->amount_due,
                'amount_paid'           => $invoice->amount_paid,
                'currency'              => $invoice->currency,
                'hosted_invoice_url'    => $invoice->hosted_invoice_url,
                'invoice_pdf_url'       => $invoice->invoice_pdf,
                'period_start'          => $invoice->period_start ? Carbon::createFromTimestamp($invoice->period_start) : null,
                'period_end'            => $invoice->period_end ? Carbon::createFromTimestamp($invoice->period_end) : null,
                'paid_at'               => Carbon::now(),
            ]
        );

        // Update subscription status if this is a subscription invoice
        if ($invoice->subscription && $client->stripe_subscription_id === $invoice->subscription) {
            $updates = ['subscription_status' => 'active'];

            // Update period end from the subscription
            try {
                $stripe = new \Stripe\StripeClient(env('STRIPE_SECRET'));
                $sub = $stripe->subscriptions->retrieve($invoice->subscription);
                $updates['subscription_ends_at'] = Carbon::createFromTimestamp($sub->current_period_end);
            } catch (\Throwable $e) {
                Log::warning('StripeWebhook: failed to fetch subscription period', [
                    'error' => $e->getMessage(),
                ]);
            }

            $client->update($updates);
            PlanService::invalidateClientPlan($client->id);
        }

        Log::info('StripeWebhook: invoice.paid', [
            'client_id'  => $client->id,
            'invoice_id' => $invoice->id,
            'amount'     => $invoice->amount_paid,
        ]);
    }

    /**
     * invoice.payment_failed — Subscription payment failed.
     */
    private function handleInvoicePaymentFailed(object $invoice): void
    {
        $client = StripeSubscriptionService::findClientByStripeCustomer($invoice->customer);
        if (!$client) {
            return;
        }

        // Upsert invoice record
        Invoice::updateOrCreate(
            ['stripe_invoice_id' => $invoice->id],
            [
                'client_id'             => $client->id,
                'stripe_subscription_id' => $invoice->subscription,
                'type'                  => 'subscription',
                'status'                => $invoice->status ?? 'open',
                'amount_due'            => $invoice->amount_due,
                'amount_paid'           => $invoice->amount_paid ?? 0,
                'currency'              => $invoice->currency,
            ]
        );

        $client->update(['subscription_status' => 'past_due']);
        PlanService::invalidateClientPlan($client->id);

        dispatch(new PaymentFailedNotificationJob(
            $client->id,
            $invoice->id,
            $invoice->amount_due ?? 0
        ));

        Log::warning('StripeWebhook: invoice.payment_failed', [
            'client_id'  => $client->id,
            'invoice_id' => $invoice->id,
            'amount_due' => $invoice->amount_due,
        ]);
    }

    /**
     * customer.subscription.updated — Plan change, status change, etc.
     */
    private function handleSubscriptionUpdated(object $subscription): void
    {
        $client = StripeSubscriptionService::findClientByStripeCustomer($subscription->customer);
        if (!$client) {
            return;
        }

        $updates = [
            'subscription_status' => StripeSubscriptionService::mapStripeStatus($subscription->status),
            'subscription_ends_at' => Carbon::createFromTimestamp($subscription->current_period_end),
        ];

        // Sync plan if the price changed (e.g., upgrade from Stripe dashboard)
        if (!empty($subscription->items->data[0]->price->id)) {
            $stripePriceId = $subscription->items->data[0]->price->id;
            $plan = StripeSubscriptionService::findPlanByStripePrice($stripePriceId);

            if ($plan && $plan->id !== $client->subscription_plan_id) {
                $updates['subscription_plan_id'] = $plan->id;
                $updates['stripe_price_id'] = $stripePriceId;
            }
        }

        // Handle cancel_at_period_end
        if ($subscription->cancel_at_period_end && $updates['subscription_status'] === 'active') {
            $updates['subscription_status'] = 'cancelled';
        }

        $client->update($updates);
        PlanService::invalidateClientPlan($client->id);
        PlanService::syncFeatureFlagsToClient($client->id);

        Log::info('StripeWebhook: subscription.updated', [
            'client_id' => $client->id,
            'status'    => $subscription->status,
        ]);
    }

    /**
     * customer.subscription.deleted — Subscription has ended.
     */
    private function handleSubscriptionDeleted(object $subscription): void
    {
        $client = StripeSubscriptionService::findClientByStripeCustomer($subscription->customer);
        if (!$client) {
            return;
        }

        $client->update([
            'subscription_status'    => 'expired',
            'stripe_subscription_id' => null,
            'stripe_price_id'        => null,
        ]);

        PlanService::invalidateClientPlan($client->id);

        Log::info('StripeWebhook: subscription.deleted', [
            'client_id' => $client->id,
        ]);
    }

    /**
     * customer.subscription.trial_will_end — 3-day trial warning.
     */
    private function handleTrialWillEnd(object $subscription): void
    {
        $client = StripeSubscriptionService::findClientByStripeCustomer($subscription->customer);
        if (!$client) {
            return;
        }

        Log::info('StripeWebhook: trial_will_end (3-day warning)', [
            'client_id' => $client->id,
            'trial_end' => $subscription->trial_end,
        ]);

        // Future: send email notification to client admin
    }
}
