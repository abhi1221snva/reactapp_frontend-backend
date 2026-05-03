<?php

namespace App\Services;

use App\Model\Client\UserPackage;
use App\Model\Master\Client;
use App\Model\Master\ClientPackage;
use App\Model\Master\Package;
use App\Model\Master\SubscriptionPlan;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * TrialPackageService
 *
 * Assigns a trial package to newly registered clients automatically.
 * Also credits the signup wallet bonus ($5.00).
 */
class TrialPackageService
{
    const SIGNUP_CREDIT_AMOUNT = 5.00;
    const SIGNUP_CREDIT_CURRENCY = 'USD';

    /**
     * Assign the trial package to a client + its admin user.
     *
     * Creates: ClientPackage → UserPackage → Subscription Plan → Wallet Credit
     *
     * @param  int $clientId  The client to assign the trial to
     * @param  int $userId    The admin user who gets the license seat
     * @return bool
     */
    public function assignTrial(int $clientId, int $userId): bool
    {
        try {
            $package = Package::find(Package::TRIAL_PACKAGE_KEY);
            if (!$package) {
                Log::error('TrialPackageService: trial package not found', [
                    'key' => Package::TRIAL_PACKAGE_KEY,
                ]);
                return false;
            }

            // Check if client already has a package
            $existing = ClientPackage::where('client_id', $clientId)->first();
            if ($existing) {
                Log::info('TrialPackageService: client already has a package', [
                    'client_id'   => $clientId,
                    'package_key' => $existing->package_key,
                ]);
                return true;
            }

            // ── Resolve trial duration from starter plan ────────────
            try {
                $starterPlan = SubscriptionPlan::getStarterPlan();
            } catch (\Throwable $e) {
                $starterPlan = null;
            }
            $trialDays = $starterPlan ? ($starterPlan->trial_days ?: 14) : 14;

            $now     = Carbon::now();
            $endDate = $now->copy()->addDays($trialDays);

            // ── Create ClientPackage (master DB) ─────────────────────
            $clientPackage                      = new ClientPackage();
            $clientPackage->client_id           = $clientId;
            $clientPackage->package_key         = Package::TRIAL_PACKAGE_KEY;
            $clientPackage->quantity             = 1;
            $clientPackage->start_time          = $now;
            $clientPackage->end_time            = $endDate;
            $clientPackage->expiry_time         = $endDate;
            $clientPackage->billed              = 1; // monthly billing cycle
            $clientPackage->payment_cent_amount = 0;
            $clientPackage->payment_time        = $now;
            $clientPackage->payment_method      = 'trial';
            $clientPackage->psp_reference       = time();
            $clientPackage->saveOrFail();

            // ── Create UserPackage (client DB) ───────────────────────
            $connName = "mysql_{$clientId}";

            if (DB::connection($connName)->getSchemaBuilder()->hasTable('user_packages')) {
                $userPackage = new UserPackage();
                $userPackage->setConnection($connName);
                $userPackage->client_package_id = $clientPackage->id;
                $userPackage->user_id           = $userId;
                $userPackage->free_call_minutes = $package->free_call_minute_monthly ?? 0;
                $userPackage->free_sms          = $package->free_sms_monthly ?? 0;
                $userPackage->free_fax          = $package->free_fax_monthly ?? 0;
                $userPackage->free_emails       = $package->free_emails_monthly ?? 0;
                $userPackage->free_reset_time   = $now->copy()->addMonth();
                $userPackage->saveOrFail();
            } else {
                Log::warning('TrialPackageService: user_packages table missing in client DB', [
                    'client_id' => $clientId,
                ]);
            }

            // ── Assign starter plan on trial with 1 seat ────────────
            if ($starterPlan) {
                Client::where('id', $clientId)->update([
                    'subscription_plan_id'    => $starterPlan->id,
                    'subscription_status'     => 'trial',
                    'billing_cycle'           => 'monthly',
                    'subscription_started_at' => $now,
                    'subscription_ends_at'    => $endDate,
                    'seat_quantity'           => 1,
                ]);

                PlanService::syncFeatureFlagsToClient($clientId);
            }

            // ── Credit $5.00 signup wallet bonus ─────────────────────
            $this->creditSignupWallet($clientId, $connName, $now);

            // ── Log subscription event ───────────────────────────────
            $this->logSubscriptionEvent($clientId, $starterPlan, $endDate, $now);

            Log::info('TrialPackageService: trial package assigned', [
                'client_id'          => $clientId,
                'user_id'            => $userId,
                'client_package_id'  => $clientPackage->id,
                'trial_days'         => $trialDays,
                'expires'            => $endDate->toDateTimeString(),
                'wallet_credit'      => self::SIGNUP_CREDIT_AMOUNT,
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::error('TrialPackageService::assignTrial failed', [
                'client_id' => $clientId,
                'user_id'   => $userId,
                'error'     => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Credit the signup wallet bonus to the client's wallet.
     */
    private function creditSignupWallet(int $clientId, string $connName, Carbon $now): void
    {
        $amount   = self::SIGNUP_CREDIT_AMOUNT;
        $currency = self::SIGNUP_CREDIT_CURRENCY;

        // Ensure wallet row exists
        $walletRow = DB::connection($connName)->table('wallet')
            ->where('currency_code', $currency)
            ->first();

        if ($walletRow) {
            DB::connection($connName)->table('wallet')
                ->where('currency_code', $currency)
                ->increment('amount', $amount);
            $newBalance = $walletRow->amount + $amount;
        } else {
            DB::connection($connName)->table('wallet')->insert([
                'amount'        => $amount,
                'currency_code' => $currency,
            ]);
            $newBalance = $amount;
        }

        // Log the transaction
        $txData = [
            'currency_code'         => $currency,
            'amount'                => $amount,
            'transaction_type'      => 'credit',
            'transaction_reference' => 'SIGNUP_CREDIT',
            'description'           => 'Signup bonus credit',
            'created_at'            => $now,
            'updated_at'            => $now,
        ];

        // Add enhanced columns if available (migration may not have run yet)
        if (DB::connection($connName)->getSchemaBuilder()->hasColumn('wallet_transactions', 'billable_type')) {
            $txData['billable_type'] = 'signup_credit';
            $txData['balance_after'] = $newBalance;
        }

        DB::connection($connName)->table('wallet_transactions')->insert($txData);

        // Denormalize to master for admin visibility
        Client::where('id', $clientId)->update([
            'wallet_balance_cents' => (int) round($newBalance * 100),
        ]);
    }

    /**
     * Log a subscription event for the trial start.
     */
    private function logSubscriptionEvent(int $clientId, ?SubscriptionPlan $plan, Carbon $endDate, Carbon $now): void
    {
        try {
            DB::connection('master')->table('subscription_events')->insert([
                'client_id'    => $clientId,
                'event_type'   => 'trial_started',
                'from_status'  => null,
                'to_status'    => 'trial',
                'plan_id'      => $plan ? $plan->id : null,
                'metadata'     => json_encode([
                    'trial_days'    => $plan ? ($plan->trial_days ?: 14) : 14,
                    'ends_at'       => $endDate->toDateTimeString(),
                    'wallet_credit' => self::SIGNUP_CREDIT_AMOUNT,
                ]),
                'triggered_by' => 'system',
                'created_at'   => $now,
            ]);
        } catch (\Throwable $e) {
            // Don't fail the entire trial assignment if event logging fails
            // (table may not exist yet on older installations)
            Log::warning('TrialPackageService: subscription event logging failed', [
                'client_id' => $clientId,
                'error'     => $e->getMessage(),
            ]);
        }
    }
}
