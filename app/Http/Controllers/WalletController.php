<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * @OA\Get(
 *   path="/wallet/balance",
 *   summary="Get client wallet balance",
 *   operationId="walletBalance",
 *   tags={"Wallet"},
 *   security={{"Bearer":{}}},
 *   @OA\Response(response=200, description="Wallet balance"),
 *   @OA\Response(response=401, description="Unauthenticated")
 * )
 *
 * @OA\Get(
 *   path="/wallet/transactions",
 *   summary="Get wallet transaction history",
 *   operationId="walletTransactions",
 *   tags={"Wallet"},
 *   security={{"Bearer":{}}},
 *   @OA\Response(response=200, description="Wallet transactions")
 * )
 */
class WalletController extends Controller
{
    function getWalletBalance(Request $request)
    {
        $response = DB::connection('mysql_' . $request->auth->parent_id)->select("SELECT amount FROM wallet");


        if (!$response) {
            return $this->successResponse("wallet data received successfully.", array( "amount" => 0) );
        } else {
            return $this->successResponse("wallet data received successfully.", (array)$response[0] );
        }
    }

    function getWalletTransactions(Request $request)
    {
        $walletTransactions = DB::connection('mysql_' . $request->auth->parent_id)
        ->select("SELECT * FROM wallet_transactions ORDER BY id DESC");
        if (count($walletTransactions) == 0) {
            return $this->successResponse("No Transactions found.", []);
        } else {
            return $this->successResponse("Transaction Retrieved successfully.", $walletTransactions );
        }
    }

}
