<?php

namespace App\Services;

use App\Model\Client\wallet;
use App\Model\Master\Client;
use App\Model\Master\Invoice;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * WalletTopUpService
 *
 * Handles wallet top-up via Stripe PaymentIntent.
 * Credits the unified wallet (client DB) and logs all transactions.
 */
class WalletTopUpService
{
    const MIN_AMOUNT      = 10;
    const PRESET_AMOUNTS  = [25, 50, 100, 250];
    const CURRENCY        = 'USD';

    /**
     * Charge the client via Stripe and credit their wallet.
     *
     * @param int    $clientId
     * @param float  $amount          Dollar amount (min $10)
     * @param string $paymentMethodId Stripe pm_* token
     * @param int    $userId          Who initiated the top-up
     * @return array
     */
    public static function topUp(int $clientId, float $amount, string $paymentMethodId, int $userId): array
    {
        if ($amount < self::MIN_AMOUNT) {
            throw new \InvalidArgumentException("Minimum top-up amount is $" . self::MIN_AMOUNT);
        }

        // 1. Ensure Stripe customer
        $stripeCustomerId = StripeSubscriptionService::ensureStripeCustomer($clientId);

        // 2. Charge via Stripe PaymentIntent
        \Stripe\Stripe::setApiKey(env('STRIPE_SECRET'));
        $amountCents = (int) round($amount * 100);

        $paymentIntent = \Stripe\PaymentIntent::create([
            'amount'         => $amountCents,
            'currency'       => 'usd',
            'customer'       => $stripeCustomerId,
            'payment_method' => $paymentMethodId,
            'off_session'    => true,
            'confirm'        => true,
            'description'    => "Wallet top-up for client #{$clientId}",
            'metadata'       => [
                'client_id' => $clientId,
                'user_id'   => $userId,
                'type'      => 'wallet_topup',
            ],
        ]);

        if ($paymentIntent->status !== 'succeeded') {
            throw new \RuntimeException("Payment failed with status: {$paymentIntent->status}");
        }

        // 3. Credit the wallet (atomic increment)
        wallet::creditCharge($amount, $clientId, self::CURRENCY);

        // 4. Log the transaction
        DB::connection('mysql_' . $clientId)->table('wallet_transactions')->insert([
            'currency_code'         => self::CURRENCY,
            'amount'                => $amount,
            'transaction_type'      => 'credit',
            'transaction_reference' => $paymentIntent->id,
            'description'           => 'Stripe wallet top-up',
            'created_at'            => Carbon::now(),
            'updated_at'            => Carbon::now(),
        ]);

        // 5. Create invoice record in master DB
        Invoice::create([
            'client_id'         => $clientId,
            'stripe_invoice_id' => 'pi_' . $paymentIntent->id, // Use PI id as reference
            'type'              => 'wallet_topup',
            'status'            => 'paid',
            'amount_due'        => $amountCents,
            'amount_paid'       => $amountCents,
            'currency'          => 'usd',
            'paid_at'           => Carbon::now(),
        ]);

        Log::info('WalletTopUpService: top-up successful', [
            'client_id'         => $clientId,
            'user_id'           => $userId,
            'amount'            => $amount,
            'payment_intent_id' => $paymentIntent->id,
        ]);

        // 6. Get updated balance and sync to master
        $newBalance = self::getBalance($clientId);

        Client::where('id', $clientId)->update([
            'wallet_balance_cents' => (int) round($newBalance * 100),
            'wallet_low_notified'  => false, // reset so notification can re-fire if needed
        ]);

        return [
            'success'              => true,
            'amount'               => $amount,
            'balance'              => $newBalance,
            'payment_intent_id'    => $paymentIntent->id,
        ];
    }

    /**
     * Get the current wallet balance for the client.
     */
    public static function getBalance(int $clientId): float
    {
        $row = DB::connection('mysql_' . $clientId)
            ->table('wallet')
            ->where('currency_code', self::CURRENCY)
            ->first();

        return $row ? (float) $row->amount : 0.00;
    }

    /**
     * Get paginated wallet transactions.
     */
    public static function getTransactions(int $clientId, int $page = 1, int $perPage = 25): array
    {
        $offset = ($page - 1) * $perPage;

        $total = DB::connection('mysql_' . $clientId)
            ->table('wallet_transactions')
            ->count();

        $rows = DB::connection('mysql_' . $clientId)
            ->table('wallet_transactions')
            ->orderBy('id', 'desc')
            ->offset($offset)
            ->limit($perPage)
            ->get();

        return [
            'data'         => $rows->toArray(),
            'total'        => $total,
            'page'         => $page,
            'per_page'     => $perPage,
            'last_page'    => (int) ceil($total / $perPage),
        ];
    }
}
