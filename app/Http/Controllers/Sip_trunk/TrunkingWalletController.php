<?php

namespace App\Http\Controllers\Sip_trunk;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Model\Client\Sip_trunk\TrunkingWalletTransaction;

class TrunkingWalletController extends Controller
{


    /**
     * @OA\Get(
     *     path="/trunking/wallet/transactions",
     *     summary="Get Wallet Transactions",
     *     description="Fetch the list of wallet transactions for the authenticated client.",
     *     tags={"TrunkingWallet"},
     *     security={{"Bearer":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="List of wallet transactions",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Transaction Retrieved successfully."),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="currency_code", type="string", example="USD"),
     *                     @OA\Property(property="amount", type="number", example=100.00),
     *                     @OA\Property(property="transaction_type", type="string", example="credit"),
     *                     @OA\Property(property="description", type="string", example="Recharge"),
     *                     @OA\Property(property="created_at", type="string", format="date-time", example="2025-04-25T10:00:00Z")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal Server Error"
     *     )
     * )
     */
    function getWalletTransactions(Request $request)
    {
        $walletTransactions = TrunkingWalletTransaction::on('mysql_' . $request->auth->parent_id)->get();

        if (count($walletTransactions) == 0) {
            return $this->successResponse("No Transactions found.", []);
        } else {
            return $this->successResponse("Transaction Retrieved successfully.", $walletTransactions->toArray());
        }
    }
}
