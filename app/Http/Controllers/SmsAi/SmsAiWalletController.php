<?php

namespace App\Http\Controllers\SmsAi;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Model\Client\SmsAi\SmsAiWalletTransaction;
use App\Model\Client\SmsAi\SmsAiWallet;


class SmsAiWalletController extends Controller
{


    /**
     * @OA\Get(
     *     path="/smsai/wallet/transactions",
     *     summary="Fetch all wallet transactions for the authenticated client",
     *     tags={"SmsAiWallet"},
     *     security={{"Bearer":{}}},
          *       @OA\Parameter(
 *          name="start",
 *          in="query",
 *          description="Start index for pagination",
 *          required=false,
 *          @OA\Schema(type="integer", default=0)
 *      ),
 *      @OA\Parameter(
 *          name="limit",
 *          in="query",
 *          description="Limit number of records returned",
 *          required=false,
 *          @OA\Schema(type="integer", default=10)
 *      ),
     *     @OA\Response(
     *         response=200,
     *         description="Wallet transactions retrieved successfully or no transactions found",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="string", example="success"),
     *             @OA\Property(property="message", type="string", example="Transaction Retrieved successfully."),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="currency_code", type="string", example="USD"),
     *                     @OA\Property(property="amount", type="number", format="float", example=50.00),
     *                     @OA\Property(property="transaction_type", type="string", example="credit"),
     *                     @OA\Property(property="transaction_reference", type="string", example="REF12345"),
     *                     @OA\Property(property="description", type="string", example="Recharge"),
     *                     @OA\Property(property="created_at", type="string", format="date-time", example="2024-04-01T10:00:00Z")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Failed to retrieve wallet transactions"
     *     )
     * )
     */

    function getWalletTransactionsold(Request $request)
    {
        $walletTransactions = SmsAiWalletTransaction::on('mysql_' . $request->auth->parent_id)->get();

        if ($walletTransactions->isEmpty()) {
            return $this->successResponse("No Transactions found.", []);
        } else {
            return $this->successResponse("Transaction Retrieved successfully.", $walletTransactions->toArray());
        }
    }
    public function getWalletTransactions(Request $request)
{
    $query = SmsAiWalletTransaction::on('mysql_' . $request->auth->parent_id)
        ->orderBy('id', 'DESC');

    // Apply pagination if start and limit are provided
    if ($request->has('start') && $request->has('limit')) {
        $start = (int) $request->input('start');
        $limit = (int) $request->input('limit');
        $query->skip($start)->take($limit);
    }

    $walletTransactions = $query->get();

    if ($walletTransactions->isEmpty()) {
        return $this->successResponse("No Transactions found.", []);
    } else {
        return $this->successResponse("Transaction Retrieved successfully.", $walletTransactions->toArray());
    }
}


    /**
     * @OA\Get(
     *     path="/smsai/wallet/amount",
     *     summary="Get wallet balance and related details for authenticated client",
     *     tags={"SmsAiWallet"},
     *     security={{"Bearer":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Wallet amount retrieved successfully or no records found",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="string", example="success"),
     *             @OA\Property(property="message", type="string", example="Transaction Retrieved successfully."),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="client_id", type="integer", example=123),
     *                     @OA\Property(property="currency_code", type="string", example="USD"),
     *                     @OA\Property(property="balance", type="number", format="float", example=150.75),
     *                     @OA\Property(property="created_at", type="string", format="date-time", example="2024-04-01T10:00:00Z"),
     *                     @OA\Property(property="updated_at", type="string", format="date-time", example="2024-04-10T14:35:22Z")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Failed to retrieve wallet amount"
     *     )
     * )
     */
    function getWalletAmount(Request $request)
    {
        $walletTransactions = SmsAiWallet::on('mysql_' . $request->auth->parent_id)->get();
        if ($walletTransactions->isEmpty()) {
            return $this->successResponse("No Transactions found.", []);
        } else {
            return $this->successResponse("Transaction Retrieved successfully.", $walletTransactions->toArray());
        }
    }
}
