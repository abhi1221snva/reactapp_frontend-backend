<?php

namespace App\Http\Controllers\Sip_trunk;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Model\Client\SmsProviders;
use Illuminate\Support\Facades\Log;


class TrunkingCallReportController extends Controller
{

    /**
     * @OA\Post(
     *     path="/trunking/report",
     *     summary="Fetch CDR Report from Telnyx",
     *     description="Retrieves a detailed call/message report from Telnyx and filters based on billing group and direction.",
     *     tags={"TrunkingCallReport"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"aggregation_type", "product_breakdown", "connections", "start_date", "end_date"},
     *             @OA\Property(property="aggregation_type", type="string", example="day"),
     *             @OA\Property(property="product_breakdown", type="boolean", example=true),
     *             @OA\Property(property="connections", type="string", example="12345678-aaaa-bbbb-cccc-123456789abc"),
     *             @OA\Property(property="start_date", type="string", format="date", example="2024-01-01"),
     *             @OA\Property(property="end_date", type="string", format="date", example="2024-01-31"),
     *             @OA\Property(property="billing_group", type="string", example="bg_123"),
     *             @OA\Property(property="tags", type="string", example="marketing"),
     *             @OA\Property(property="direction", type="string", example="outbound")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Filtered report data",
     *         @OA\JsonContent(
     *             @OA\Property(property="result", type="array", @OA\Items(type="object"))
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Server Error"
     *     )
     * )
     */
    public function getReport(Request $request)
    {
        $result_data = [];
        // $searchTerm = isset($request->data['phone']) ? $request->data['phone'] : '';
        $aggregation_type = $request->aggregation_type;
        $product_breakdown = $request->product_breakdown;
        $connections = $request->connections;
        $start_date = $request->start_date;
        $end_date = $request->end_date;
        $billing_group = $request->billing_group;
        $tags = $request->tags;
        $direction = $request->direction;
        $database = "mysql_" . $request->auth->parent_id;
        $sms_setting = SmsProviders::on($database)->where("status", '1')->where('provider', 'telnyx')->get()->first();
        $api_key = $sms_setting->api_key;
        $url = env('TELNYX_CDR_REPORT_URL');
        $url .= '?aggregation_type=' . urlencode($aggregation_type);
        $url .= '&product_breakdown=' . urlencode($product_breakdown);
        $url .= '&start_date=' . urlencode($start_date);
        $url .= '&end_date=' . urlencode($end_date);
        $url .= '&connections=' . urlencode($connections);

        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => $url,
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
        // Filter the results based on the conditions
        if ($billing_group !== null) {
            // Check if 'result' is set and is an array
            if (isset($result_data['result']) && is_array($result_data['result'])) {
                $filteredData = [];

                foreach ($result_data['result'] as $item) {
                    // Assuming the key 'billing_group_id' and 'direction' exist in your data structure
                    $directionCondition = isset($item['direction']) && $item['direction'] == $direction;
                    $billingGroupCondition = isset($item['billing_group_id']) && $item['billing_group_id'] == $billing_group;

                    if ($billingGroupCondition) {
                        $filteredData[] = $item;
                    } else {
                        // Log or print the details of items that don't meet both conditions
                        Log::info('Item not included', ['item' => $item, 'billing_group_id' => $item['billing_group_id'], 'direction' => $item['direction']]);
                    }
                }

                $result_data['result'] = $filteredData;
                Log::info('Filtered Data', ['result' => $filteredData]);
            } else {
                // Handle the case where 'result' is not set or not an array
                $result_data['result'] = [];
            }
        }
        if ($direction !== null) {
            // Check if 'result' is set and is an array
            if (isset($result_data['result']) && is_array($result_data['result'])) {
                $filteredData2 = [];

                foreach ($result_data['result'] as $item) {
                    // Assuming the key 'billing_group_id' and 'direction' exist in your data structure
                    $directionCondition = isset($item['direction']) && $item['direction'] == $direction;

                    if ($directionCondition) {
                        $filteredData2[] = $item;
                    } else {
                        // Log or print the details of items that don't meet both conditions
                        Log::info('Item not included', ['item' => $item, 'billing_group_id' => $item['billing_group_id'], 'direction' => $item['direction']]);
                    }
                }

                $result_data['result'] = $filteredData2;
                Log::info('Filtered Data', ['result' => $filteredData2]);
            } else {
                // Handle the case where 'result' is not set or not an array
                $result_data['result'] = [];
            }
        }
        // Log::info('reached',['result_data'=>$result_data]);

        return $result_data;
    }

    /**
     * @OA\Get(
     *     path="/trunking/connections",
     *     summary="Get Telnyx Connections",
     *     description="Fetches a list of Telnyx trunking connections using the client's Telnyx API key.",
     *     tags={"TrunkingCallReport"},
     *     security={{"Bearer":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="List of Telnyx connections retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", type="array", @OA\Items(type="object"))
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Server Error"
     *     )
     * )
     */
    public function getConnection(Request $request)
    {
        $result_data = [];
        $database = "mysql_" . $request->auth->parent_id;

        $sms_setting = SmsProviders::on($database)->where("status", '1')->where('provider', 'telnyx')->get()->first();
        $api_key = $sms_setting->api_key;
        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => 'https://api.telnyx.com/v2/connections',
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

    /**
     * @OA\Get(
     *     path="/trunking/tags",
     *     summary="Get Telnyx Telephony Credential Tags",
     *     description="Fetches a list of telephony credential tags from Telnyx using the client's API key.",
     *     tags={"TrunkingCallReport"},
     *     security={{"Bearer":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="List of telephony credential tags retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", type="array", @OA\Items(type="object"))
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Server Error"
     *     )
     * )
     */
    public function getTags(Request $request)
    {
        $result_data = [];
        $database = "mysql_" . $request->auth->parent_id;

        $sms_setting = SmsProviders::on($database)->where("status", '1')->where('provider', 'telnyx')->get()->first();
        $api_key = $sms_setting->api_key;
        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => 'https://api.telnyx.com/v2/telephony_credentials/tags',
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

    /**
     * @OA\Get(
     *     path="/trunking/billing_group",
     *     summary="Get Telnyx Billing Groups",
     *     description="Fetches a list of billing groups from the Telnyx API using the client's stored API key.",
     *     tags={"TrunkingCallReport"},
     *     security={{"Bearer":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="List of billing groups retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", type="array", @OA\Items(type="object"))
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal Server Error"
     *     )
     * )
     */
    public function getBillingGroup(Request $request)
    {
        $result_data = [];
        $database = "mysql_" . $request->auth->parent_id;

        $sms_setting = SmsProviders::on($database)->where("status", '1')->where('provider', 'telnyx')->get()->first();
        $api_key = $sms_setting->api_key;
        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => 'https://api.telnyx.com/v2/billing_groups',
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
