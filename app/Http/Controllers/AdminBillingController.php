<?php

namespace App\Http\Controllers;

use App\Model\Master\Client;
use App\Model\Master\Invoice;
use App\Model\Master\SubscriptionPlan;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Super-admin billing dashboard endpoints.
 *
 * All routes require jwt.auth + auth.superadmin middleware.
 */
class AdminBillingController extends Controller
{
    /**
     * GET /admin/billing/dashboard
     *
     * High-level billing metrics: plan distribution, MRR, wallet revenue,
     * per-plan client counts, expiring trials.
     */
    public function dashboard(Request $request)
    {
        $now        = Carbon::now();
        $monthStart = $now->copy()->startOfMonth();

        // Plan status distribution
        $planDistribution = Client::where('is_deleted', 0)
            ->whereNotNull('subscription_plan_id')
            ->select('subscription_status', DB::raw('COUNT(*) as count'))
            ->groupBy('subscription_status')
            ->pluck('count', 'subscription_status')
            ->toArray();

        // Revenue this month
        $mrrCents = Invoice::where('status', 'paid')
            ->where('type', 'subscription')
            ->where('paid_at', '>=', $monthStart)
            ->sum('amount_paid');

        $walletRevenueCents = Invoice::where('status', 'paid')
            ->where('type', 'wallet_topup')
            ->where('paid_at', '>=', $monthStart)
            ->sum('amount_paid');

        // Per-plan breakdown
        $planBreakdown = Client::where('clients.is_deleted', 0)
            ->whereNotNull('clients.subscription_plan_id')
            ->join('subscription_plans', 'clients.subscription_plan_id', '=', 'subscription_plans.id')
            ->select(
                'subscription_plans.name as plan_name',
                'subscription_plans.slug as plan_slug',
                'clients.subscription_status',
                DB::raw('COUNT(*) as client_count')
            )
            ->groupBy('subscription_plans.name', 'subscription_plans.slug', 'clients.subscription_status')
            ->get();

        // Trial clients expiring in next 7 days
        $expiringTrials = Client::where('subscription_status', 'trial')
            ->where('subscription_ends_at', '<=', $now->copy()->addDays(7))
            ->where('subscription_ends_at', '>', $now)
            ->where('is_deleted', 0)
            ->count();

        // Total wallet balance across all clients
        $totalWalletCents = Client::where('is_deleted', 0)->sum('wallet_balance_cents');

        // Per-seat metrics (MRR = sum of each client's seat_quantity * their plan's unit_price_cents)
        $totalSeats = (int) Client::where('is_deleted', 0)
            ->whereIn('subscription_status', ['active', 'trial'])
            ->sum('seat_quantity');

        $seatMrr = (int) DB::connection('master')->table('clients')
            ->join('subscription_plans', 'clients.subscription_plan_id', '=', 'subscription_plans.id')
            ->where('clients.is_deleted', 0)
            ->whereIn('clients.subscription_status', ['active', 'trial'])
            ->selectRaw('SUM(clients.seat_quantity * subscription_plans.unit_price_cents) as total')
            ->value('total') ?? 0;

        return $this->successResponse('OK', [
            'plan_distribution'     => $planDistribution,
            'mrr_cents'             => (int) $mrrCents,
            'seat_mrr'              => $seatMrr,
            'total_seats'           => $totalSeats,
            'avg_price_per_seat'    => $totalSeats > 0 ? (int) round($seatMrr / $totalSeats) : 0,
            'wallet_revenue_cents'  => (int) $walletRevenueCents,
            'total_wallet_cents'    => (int) $totalWalletCents,
            'plan_breakdown'        => $planBreakdown,
            'expiring_trials_7d'    => $expiringTrials,
            'total_clients'         => Client::where('is_deleted', 0)->count(),
        ]);
    }

    /**
     * GET /admin/billing/clients
     *
     * Paginated list of all clients with billing status, plan, and wallet balance.
     * Supports filtering by status and searching by company name.
     */
    public function clients(Request $request)
    {
        $query = Client::where('clients.is_deleted', 0)
            ->leftJoin('subscription_plans', 'clients.subscription_plan_id', '=', 'subscription_plans.id')
            ->select(
                'clients.id',
                'clients.company_name',
                'clients.subscription_status',
                'clients.billing_cycle',
                'clients.subscription_started_at',
                'clients.subscription_ends_at',
                'clients.grace_period_ends_at',
                'clients.wallet_balance_cents',
                'clients.stripe_customer_id',
                'clients.seat_quantity',
                'subscription_plans.name as plan_name',
                'subscription_plans.slug as plan_slug',
                'subscription_plans.price_monthly'
            );

        // Filter by subscription status
        if ($request->has('status') && $request->input('status')) {
            $query->where('clients.subscription_status', $request->input('status'));
        }

        // Search by company name
        if ($request->has('search') && $request->input('search')) {
            $query->where('clients.company_name', 'like', '%' . $request->input('search') . '%');
        }

        $perPage = min(50, max(1, (int) $request->input('per_page', 25)));
        $result  = $query->orderBy('clients.id', 'desc')->paginate($perPage);

        return $this->successResponse('OK', $result->toArray());
    }

    /**
     * POST /admin/billing/clients/{id}/credit-wallet
     *
     * Admin manual wallet credit or debit adjustment with audit trail.
     */
    public function creditWallet(Request $request, int $id)
    {
        $this->validate($request, [
            'amount' => 'required|numeric|min:-10000|max:10000',
            'reason' => 'required|string|max:255',
        ]);

        $client = Client::find($id);
        if (!$client || $client->is_deleted) {
            return $this->failResponse('Client not found', [], null, 404);
        }

        $amount = (float) $request->input('amount');
        $conn   = "mysql_{$id}";
        $type   = $amount >= 0 ? 'credit' : 'debit';
        $now    = Carbon::now();

        try {
            $newBalance = DB::connection($conn)->transaction(function () use (
                $conn, $id, $amount, $type, $request, $now
            ) {
                $wallet = DB::connection($conn)->table('wallet')
                    ->where('currency_code', 'USD')
                    ->lockForUpdate()
                    ->first();

                $currentBalance = $wallet ? (float) $wallet->amount : 0;
                $newBalance     = round($currentBalance + $amount, 4);

                if ($newBalance < 0) {
                    throw new \InvalidArgumentException(
                        "Adjustment would result in negative balance ({$currentBalance} + {$amount} = {$newBalance})"
                    );
                }

                if ($wallet) {
                    DB::connection($conn)->table('wallet')
                        ->where('currency_code', 'USD')
                        ->update(['amount' => $newBalance]);
                } else {
                    DB::connection($conn)->table('wallet')->insert([
                        'amount'        => $newBalance,
                        'currency_code' => 'USD',
                    ]);
                }

                $txData = [
                    'currency_code'         => 'USD',
                    'amount'                => abs($amount),
                    'transaction_type'      => $type,
                    'transaction_reference' => 'ADMIN_ADJUST_' . time(),
                    'description'           => 'Admin adjustment: ' . $request->input('reason'),
                    'created_at'            => $now,
                    'updated_at'            => $now,
                ];

                // Add enhanced columns if migration has run
                if (DB::connection($conn)->getSchemaBuilder()->hasColumn('wallet_transactions', 'billable_type')) {
                    $txData['actor_id']      = $request->auth->id;
                    $txData['billable_type'] = 'admin_adjust';
                    $txData['balance_after'] = $newBalance;
                }

                DB::connection($conn)->table('wallet_transactions')->insert($txData);

                return $newBalance;
            });

            // Update denormalized master balance
            Client::where('id', $id)->update([
                'wallet_balance_cents' => (int) round($newBalance * 100),
                'wallet_low_notified'  => false, // reset notification flag
            ]);

            Log::info('AdminBillingController: wallet adjusted', [
                'client_id'   => $id,
                'admin_id'    => $request->auth->id,
                'amount'      => $amount,
                'reason'      => $request->input('reason'),
                'new_balance' => $newBalance,
            ]);

            return $this->successResponse('Wallet adjusted', [
                'client_id'   => $id,
                'amount'      => $amount,
                'type'        => $type,
                'new_balance' => $newBalance,
                'reason'      => $request->input('reason'),
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->failResponse($e->getMessage(), [], null, 422);
        } catch (\Throwable $e) {
            Log::error('AdminBillingController: wallet adjustment failed', [
                'client_id' => $id,
                'error'     => $e->getMessage(),
            ]);
            return $this->failResponse('Failed to adjust wallet: ' . $e->getMessage(), [], null, 500);
        }
    }
}
