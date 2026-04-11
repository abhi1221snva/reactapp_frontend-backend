<?php

namespace App\Services\Rvm;

use App\Model\Master\Rvm\Wallet;
use App\Model\Master\Rvm\WalletLedger;
use App\Services\Rvm\Exceptions\InsufficientCreditsException;
use App\Services\Rvm\Support\Ulid;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * RVM credit accounting.
 *
 * Contract:
 *   reserve($clientId, $amount)   → returns reservation_id; debits balance, credits reserved
 *   commit($reservationId)        → drops reserved by amount (no balance change)
 *   refund($reservationId)        → moves amount from reserved back to balance
 *   topup($clientId, $amount)     → credits balance (Stripe webhook, manual adjust)
 *
 * All mutations are atomic via conditional UPDATE statements. A ledger row
 * is appended on every call so reconciliation = sum(ledger) for any client.
 */
class RvmWalletService
{
    /**
     * Atomically reserve $amountCents for an upcoming drop.
     * Returns the reservation id, or throws InsufficientCreditsException.
     */
    public function reserve(int $clientId, int $amountCents): string
    {
        if ($amountCents <= 0) {
            // Free drops still get a zero reservation for audit symmetry.
            return $this->writeLedger($clientId, 'reserve', 0, null, null);
        }

        return DB::connection('master')->transaction(function () use ($clientId, $amountCents) {
            $this->ensureWalletRow($clientId);

            $affected = DB::connection('master')
                ->table('rvm_wallet')
                ->where('client_id', $clientId)
                ->where('balance_cents', '>=', $amountCents)
                ->update([
                    'balance_cents' => DB::raw("balance_cents - {$amountCents}"),
                    'reserved_cents' => DB::raw("reserved_cents + {$amountCents}"),
                    'updated_at' => Carbon::now(),
                ]);

            if ($affected === 0) {
                $balance = (int) DB::connection('master')
                    ->table('rvm_wallet')
                    ->where('client_id', $clientId)
                    ->value('balance_cents');
                throw new InsufficientCreditsException($balance, $amountCents);
            }

            $balanceAfter = (int) DB::connection('master')
                ->table('rvm_wallet')
                ->where('client_id', $clientId)
                ->value('balance_cents');

            return $this->writeLedger($clientId, 'reserve', -$amountCents, $balanceAfter, null);
        });
    }

    /**
     * Commit a previously-reserved amount (success path).
     * The reservation row is identified by reservation_id in the ledger.
     */
    public function commit(int $clientId, string $reservationId): void
    {
        $reserveRow = WalletLedger::on('master')
            ->where('client_id', $clientId)
            ->where('reservation_id', $reservationId)
            ->where('type', 'reserve')
            ->first();

        if (!$reserveRow) return;
        $amount = abs((int) $reserveRow->amount_cents);
        if ($amount === 0) return;

        DB::connection('master')->transaction(function () use ($clientId, $amount, $reservationId) {
            DB::connection('master')
                ->table('rvm_wallet')
                ->where('client_id', $clientId)
                ->update([
                    'reserved_cents' => DB::raw("reserved_cents - {$amount}"),
                    'updated_at' => Carbon::now(),
                ]);

            $balanceAfter = (int) DB::connection('master')
                ->table('rvm_wallet')
                ->where('client_id', $clientId)
                ->value('balance_cents');

            $this->writeLedger($clientId, 'commit', 0, $balanceAfter, $reservationId);
        });
    }

    /**
     * Refund a previously-reserved amount (failure path).
     */
    public function refund(int $clientId, string $reservationId): void
    {
        $reserveRow = WalletLedger::on('master')
            ->where('client_id', $clientId)
            ->where('reservation_id', $reservationId)
            ->where('type', 'reserve')
            ->first();

        if (!$reserveRow) return;
        $amount = abs((int) $reserveRow->amount_cents);
        if ($amount === 0) return;

        DB::connection('master')->transaction(function () use ($clientId, $amount, $reservationId) {
            DB::connection('master')
                ->table('rvm_wallet')
                ->where('client_id', $clientId)
                ->update([
                    'balance_cents' => DB::raw("balance_cents + {$amount}"),
                    'reserved_cents' => DB::raw("reserved_cents - {$amount}"),
                    'updated_at' => Carbon::now(),
                ]);

            $balanceAfter = (int) DB::connection('master')
                ->table('rvm_wallet')
                ->where('client_id', $clientId)
                ->value('balance_cents');

            $this->writeLedger($clientId, 'refund', $amount, $balanceAfter, $reservationId);
        });
    }

    public function topup(int $clientId, int $amountCents, string $reference): void
    {
        DB::connection('master')->transaction(function () use ($clientId, $amountCents, $reference) {
            $this->ensureWalletRow($clientId);

            DB::connection('master')
                ->table('rvm_wallet')
                ->where('client_id', $clientId)
                ->update([
                    'balance_cents' => DB::raw("balance_cents + {$amountCents}"),
                    'updated_at' => Carbon::now(),
                ]);

            $balanceAfter = (int) DB::connection('master')
                ->table('rvm_wallet')
                ->where('client_id', $clientId)
                ->value('balance_cents');

            WalletLedger::create([
                'client_id' => $clientId,
                'type' => 'topup',
                'amount_cents' => $amountCents,
                'balance_after' => $balanceAfter,
                'reference' => $reference,
                'created_at' => Carbon::now(),
            ]);
        });
    }

    public function balance(int $clientId): int
    {
        $this->ensureWalletRow($clientId);
        return (int) DB::connection('master')
            ->table('rvm_wallet')
            ->where('client_id', $clientId)
            ->value('balance_cents');
    }

    private function ensureWalletRow(int $clientId): void
    {
        DB::connection('master')
            ->table('rvm_wallet')
            ->insertOrIgnore([
                'client_id' => $clientId,
                'balance_cents' => 0,
                'reserved_cents' => 0,
                'low_balance_threshold_cents' => 1000,
                'low_balance_notified' => false,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
    }

    private function writeLedger(int $clientId, string $type, int $amount, ?int $balanceAfter, ?string $reservationId): string
    {
        $reservationId ??= Ulid::generate();

        WalletLedger::create([
            'client_id' => $clientId,
            'reservation_id' => $reservationId,
            'type' => $type,
            'amount_cents' => $amount,
            'balance_after' => $balanceAfter ?? 0,
            'created_at' => Carbon::now(),
        ]);

        return $reservationId;
    }
}
