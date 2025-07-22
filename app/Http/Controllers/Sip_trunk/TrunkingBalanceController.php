<?php

namespace App\Http\Controllers\Sip_trunk;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Model\Client\SmsProviders;
use Illuminate\Support\Facades\Log;


class TrunkingBalanceController extends Controller
{


    /**
     * @OA\Get(
     *     path="/trunking/balance",
     *     summary="Fetch Telnyx balance for the authenticated client's SMS account",
     *     tags={"TrunkingBalance"},
     *     security={{"Bearer":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Balance retrieved successfully from Telnyx",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="available_balance", type="number", format="float", example=97.56),
     *                 @OA\Property(property="currency", type="string", example="USD")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized or missing Telnyx API key"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Failed to retrieve Telnyx balance"
     *     )
     * )
     */
    public function getBalance(Request $request)
    {
        $result_data = [];
        $database = "mysql_" . $request->auth->parent_id;

        $sms_setting = SmsProviders::on($database)->where("status", '1')->where('provider', 'telnyx')->get()->first();
        $api_key = $sms_setting->api_key;
        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => 'https://api.telnyx.com/v2/balance',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_HTTPHEADER => array(
                'Accept: application/json',
                'Authorization: Bearer ' . $api_key,

            ),
        ));

        $response = curl_exec($curl);
        $result_data = json_decode($response, true);
        curl_close($curl);
        return $result_data;
    }
}
