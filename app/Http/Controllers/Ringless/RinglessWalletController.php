<?php

namespace App\Http\Controllers\Ringless;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Model\Client\Ringless\RinglessWalletTransaction;
use App\Model\Client\Ringless\RinglessWallet;
use App\Model\Master\Client;
use Illuminate\Support\Facades\DB;




class RinglessWalletController extends Controller
{

    /**
     * @OA\Get(
     *     path="/ringless/wallet/transactions",
     *     summary="Get wallet transactions for the authenticated client",
     *     tags={"RinglessWallet"},
     *     security={{"Bearer":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="List of wallet transactions or message if none found",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Transaction Retrieved successfully."),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="currency_code", type="string", example="USD"),
     *                     @OA\Property(property="amount", type="number", format="float", example=100.0),
     *                     @OA\Property(property="transaction_type", type="string", example="credit"),
     *                     @OA\Property(property="transaction_reference", type="string", example=""),
     *                     @OA\Property(property="description", type="string", example="Recharge"),
     *                     @OA\Property(property="created_at", type="string", format="date-time", example="2025-04-24T10:00:00Z"),
     *                     @OA\Property(property="updated_at", type="string", format="date-time", example="2025-04-24T10:00:00Z")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthenticated"
     *     )
     * )
     */

    function getWalletTransactions(Request $request)
    {
        $walletTransactions = RinglessWalletTransaction::on('mysql_' . $request->auth->parent_id)->get();
        if ($walletTransactions->isEmpty()) {
            return $this->successResponse("No Transactions found.", []);
        } else {
            return $this->successResponse("Transaction Retrieved successfully.", $walletTransactions->toArray());
        }
    }


    /**
     * @OA\Get(
     *     path="/ringless/wallet/amount",
     *     summary="Get wallet balance or entries for the authenticated client",
     *     tags={"RinglessWallet"},
     *     security={{"Bearer":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Wallet data retrieved successfully or no transactions found.",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Transaction Retrieved successfully."),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="client_id", type="integer", example=101),
     *                     @OA\Property(property="currency_code", type="string", example="USD"),
     *                     @OA\Property(property="amount", type="number", format="float", example=250.00),
     *                     @OA\Property(property="created_at", type="string", format="date-time", example="2025-04-24T10:00:00Z"),
     *                     @OA\Property(property="updated_at", type="string", format="date-time", example="2025-04-24T10:00:00Z")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthenticated"
     *     )
     * )
     */

    function getWalletAmount(Request $request)
    {
        $walletTransactions = RinglessWallet::on('mysql_' . $request->auth->parent_id)->get();
        if ($walletTransactions->isEmpty()) {
            return $this->successResponse("No Transactions found.", []);
        } else {
            return $this->successResponse("Transaction Retrieved successfully.", $walletTransactions->toArray());
        }
    }

    function redeemAmount(Request $request)
    {
        $intCharge = '0.005';
        $currencyCode = 'USD';
        $api_key = $request->client_api_key;

        $sql = "SELECT * FROM clients  WHERE api_key like '%$api_key%' and rvm_status = '1'";
        $clientData = DB::connection('master')->selectOne($sql);

        if ($clientData) {
            $parent_id = $clientData->id;
            RinglessWallet::debitCharge($intCharge, $parent_id, $currencyCode);
            $walletTransactions = RinglessWallet::on('mysql_' . $parent_id)->get();

            if ($walletTransactions->isEmpty()) {
                return $this->successResponse("No Transactions found.", []);
            } else {
                return $this->successResponse("Amout Redeem successfully.", $walletTransactions->toArray());
            }
        }
    }
}
