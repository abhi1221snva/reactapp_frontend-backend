<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
        $walletTransactions = DB::connection('mysql_' . $request->auth->parent_id)->select("SELECT * FROM wallet_transactions");

        if (count($walletTransactions) == 0) {
            return $this->successResponse("No Transactions found.", []);
        } else {
            return $this->successResponse("Transaction Retrieved successfully.", $walletTransactions );
        }
    }

}
