<?php

namespace App\Services;

use App\Jobs\WalletLowBalanceNotificationJob;
use App\Model\Master\Client;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * WalletGuardService
 *
 * Pre-checks wallet balance before billable actions and debits
 * the wallet atomically after the action completes.
 *
 * Usage flow:
 *   1. WalletBalanceMiddleware calls canAfford() before the route handler
 *   2. The controller/service performs the action (call, SMS, etc.)
 *   3. After success, the service calls debit() to charge the wallet
 */
class WalletGuardService
{
    // Per-unit costs — move to a billing_rates table or subscription_plans for admin control
    const COST_PER_CALL_MINUTE = 0.015;  // $0.015/min
    const COST_PER_SMS         = 0.0075; // $0.0075/segment
    const MIN_BALANCE_CALL     = 0.05;   // need at least $0.05 to start a call
    const MIN_BALANCE_SMS      = 0.01;   // need at least $0.01 to send SMS

    /**
     * Check if the client can afford the action.
     *
     * @return array{allowed: bool, balance: float, required: float, shortfall: float}
     */
    public static function canAfford(int $clientId, string $action, int $units = 1): array
    {
        $balance = self::getBalance($clientId);

        $required = match ($action) {
            'call' => self::MIN_BALANCE_CALL,
            'sms'  => self::COST_PER_SMS * $units,
            default => 0,
        };

        return [
            'allowed'   => $balance >= $required,
            'balance'   => $balance,
            'required'  => $required,
            'shortfall' => max(0, round($required - $balance, 4)),
        ];
    }

    /**
     * Debit the wallet after a billable action completes.
     *
     * Uses SELECT ... FOR UPDATE to prevent race conditions.
     * Idempotent: skips if a debit for the same billable_type+billable_id exists.
     *
     * @param int      $clientId
     * @param string   $type       'call' | 'sms'
     * @param float    $amount     Dollar amount to debit
     * @param int      $actorId    User who triggered the action
     * @param int|null $billableId FK to the call/sms record
     * @return array{success: bool, balance: float}
     */
    public static function debit(
        int     $clientId,
        string  $type,
        float   $amount,
        int     $actorId,
        ?int    $billableId = null
    ): array {
        if ($amount <= 0) {
            return ['success' => true, 'balance' => self::getBalance($clientId)];
        }

        $conn = "mysql_{$clientId}";

        return DB::connection($conn)->transaction(function () use (
            $conn, $clientId, $type, $amount, $actorId, $billableId
        ) {
            // Idempotency check — prevent double-charging on retries
            if ($billableId !== null) {
                $exists = DB::connection($conn)->table('wallet_transactions')
                    ->where('billable_type', $type)
                    ->where('billable_id', $billableId)
                    ->where('transaction_type', 'debit')
                    ->exists();

                if ($exists) {
                    return [
                        'success'   => true,
                        'balance'   => self::getBalance($clientId),
                        'duplicate' => true,
                    ];
                }
            }

            // Lock the wallet row
            $wallet = DB::connection($conn)->table('wallet')
                ->where('currency_code', 'USD')
                ->lockForUpdate()
                ->first();

            if (!$wallet || $wallet->amount < $amount) {
                return [
                    'success' => false,
                    'balance' => $wallet ? (float) $wallet->amount : 0,
                ];
            }

            $newBalance = round($wallet->amount - $amount, 4);

            DB::connection($conn)->table('wallet')
                ->where('currency_code', 'USD')
                ->update(['amount' => $newBalance]);

            DB::connection($conn)->table('wallet_transactions')->insert([
                'currency_code'         => 'USD',
                'amount'                => $amount,
                'transaction_type'      => 'debit',
                'transaction_reference' => strtoupper($type) . '_' . ($billableId ?: time()),
                'description'           => ucfirst($type) . ' charge',
                'actor_id'              => $actorId,
                'billable_type'         => $type,
                'billable_id'           => $billableId,
                'balance_after'         => $newBalance,
                'created_at'            => Carbon::now(),
                'updated_at'            => Carbon::now(),
            ]);

            // Denormalize balance to master for admin queries
            Client::where('id', $clientId)->update([
                'wallet_balance_cents' => (int) round($newBalance * 100),
            ]);

            // Check low balance threshold
            self::checkLowBalance($clientId, $newBalance);

            return ['success' => true, 'balance' => $newBalance];
        });
    }

    /**
     * Get the current wallet balance.
     */
    public static function getBalance(int $clientId): float
    {
        return WalletTopUpService::getBalance($clientId);
    }

    /**
     * Check low-balance threshold and flag for notification.
     *
     * Sets wallet_low_notified=true when balance drops below threshold.
     * Resets the flag when balance recovers above threshold (e.g. after top-up).
     */
    private static function checkLowBalance(int $clientId, float $balance): void
    {
        try {
            $client = Client::find($clientId);
            if (!$client) {
                return;
            }

            $thresholdDollars = ($client->wallet_low_threshold_cents ?? 200) / 100;

            if ($balance <= $thresholdDollars && !$client->wallet_low_notified) {
                Client::where('id', $clientId)->update(['wallet_low_notified' => true]);

                Log::info('WalletGuardService: low balance notification triggered', [
                    'client_id' => $clientId,
                    'balance'   => $balance,
                    'threshold' => $thresholdDollars,
                ]);
                dispatch(new WalletLowBalanceNotificationJob($clientId, $balance, $thresholdDollars));
            }

            // Reset flag when balance recovers above threshold
            if ($balance > $thresholdDollars && $client->wallet_low_notified) {
                Client::where('id', $clientId)->update(['wallet_low_notified' => false]);
            }
        } catch (\Throwable $e) {
            Log::warning('WalletGuardService: checkLowBalance failed', [
                'client_id' => $clientId,
                'error'     => $e->getMessage(),
            ]);
        }
    }
}
