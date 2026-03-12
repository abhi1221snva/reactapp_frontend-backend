<?php

namespace App\Http\Controllers;

use App\Model\Dids;

/**
 * @OA\Post(
 *   path="/get-did-list-for-areacode",
 *   summary="Get available DIDs for an area code",
 *   operationId="buyNoAreaCodeGetDids",
 *   tags={"DIDs"},
 *   security={{"Bearer":{}}},
 *   @OA\RequestBody(@OA\JsonContent(
 *     @OA\Property(property="area_code", type="string")
 *   )),
 *   @OA\Response(response=200, description="Available DIDs for area code"),
 *   @OA\Response(response=401, description="Unauthenticated")
 * )
 *
 * @OA\Post(
 *   path="/buy-save-selected-did-areacode",
 *   summary="Buy and save a DID selected from area code search",
 *   operationId="buyNoAreaCodeSaveDid",
 *   tags={"DIDs"},
 *   security={{"Bearer":{}}},
 *   @OA\RequestBody(@OA\JsonContent(
 *     @OA\Property(property="did", type="string"),
 *     @OA\Property(property="area_code", type="string")
 *   )),
 *   @OA\Response(response=200, description="DID purchased and saved")
 * )
 */
use App\Model\Client\Did;
use App\Model\Client\UploadHistoryDid;
use App\Model\Client\CallTimings;
use App\Model\Client\Departments;
use App\Model\Client\Holiday;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\DB;
use File;
use Illuminate\Auth\AuthManager;
use App\Model\Authentication;
use Session;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Plivo\RestClient;
use App\Model\Client\SmsProviders;
use App\Model\User;
use App\Model\Master\UserExtension;
use Telnyx\Telnyx;
use Illuminate\Support\Facades\Log;
use GuzzleHttp\Client;
use GuzzleHttp\Promise;



class BuyNoAreaCodeController extends Controller {

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    private $request;
   

    public function __construct(Request $request, Dids $dids, CallTimings $callTimings,
            Departments $departments, Holiday $holiday) {
        $this->request = $request;
        $this->model = $dids;
        $this->modelCallTimings = $callTimings;
        $this->modelDepartments = $departments;
        $this->modelHoliday = $holiday;
        $this->title = $request->route('title');
    }




    /**
    * Get Did From Did Sale
    * @param type $request
    * @return string
    */
   
    

    // private function getDidfromDidSale($request)
    // {
    //     Log::info('Reached', [$request->all()]);
    //     $result = [];
    //     // $number = str_replace(['(', ')', '_', '-', ' '], '', $request->data['phone']);
    //     $show = 1;
    //     $country_codes = isset($request->data['country']) ? $request->data['country'] : ['US']; // Default to US if not set
    //     $number_type = isset($request->data['numberType']) ? $request->data['numberType'] : 'local';
    //     $states = isset($request->data['state']) ? (array)$request->data['state'] : []; // Ensure states is an array
    
    //     $totalToShow = $show; // Total number of entries to fetch
    
    //     foreach ($country_codes as $country_code) {
        
    //         $url = env('DID_SALE_API_URL') . "products/ListNumberAPI";
    
    //         $params = [
    //             'number' => $country_code,
    //             'page_number' => 1,
    //             'show' => 1, // Adjust show based on remaining entries to fetch
    //             'country_code' => $country_code,
    //         ];
    
    //         $url .= '?' . http_build_query($params);
    
    //         $ch = curl_init();
    //         curl_setopt($ch, CURLOPT_URL, $url);
    //         curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    //         curl_setopt($ch, CURLOPT_HEADER, false);
    //         curl_setopt($ch, CURLOPT_HTTPHEADER, [
    //             "Accept: application/json",
    //             "Authorization: Basic " . base64_encode(env('DID_SALE_SERVICE_KEY') . ':' . env('DID_SALE_SERVICE_TOKEN'))
    //         ]);
    
    //         $response = curl_exec($ch);
    //         $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE); // Get HTTP response code
    //         curl_close($ch);
    
    //         if ($http_code == 200) {
    //             $response = json_decode($response, true);
    
    //             if (isset($response['status']) && $response['status']) {
    //                 foreach ($response['numbers'] as $row) {
    //                     if (empty($states) || in_array($row['state'], $states)) {
    //                         $area_code = substr($row['number'], 0, 3);
    //                         $temp = [
    //                             "<input type='checkbox' id='select_all_checkbox_" . $row['number'] . "' value='" . $row['number'] . "' data-ratecenter='" . $row['ratecenter'] . "' data-referenceid='" . $row['reference_id'] . "' data-state='" . $row['state'] . "' data-didtype='Metered' class='did_checkbox' /><label for='select_all_checkbox_" . $row['number'] . "'></label>",
    //                             $row['number'],
    //                             $area_code,
    //                             $row['state'],
    //                             "Metered"
    //                         ];
    //                         $result[] = $temp;
    //                     }
    //                 }
    //             } else {
    //                 Log::warning("Failed to fetch data for country code");
    //             }
    //         } else {
    //             Log::error("Failed to fetch data from DID Sale API. HTTP Code: $http_code");
    //         }
    //     }
    
    //     return $result;
    // }
    
  
    
    private function getDidfromDidSale($request)
{
    Log::info('Reached', [$request->all()]);
    $result = [];
    $show = 1;
    $country_codes = isset($request->data['country']) ? $request->data['country'] : ['US']; // Default to US if not set
    $states = isset($request->data['state']) ? (array)$request->data['state'] : []; // Ensure states is an array

    foreach ($country_codes as $country_code) {
        $url = env('DID_SALE_API_URL') . "products/ListNumberAPI";

        $params = [
            'number' => $country_code,
            'page_number' => 1,
            'show' => $show, // Adjust show based on remaining entries to fetch
            'country_code' => $country_code,
        ];

        $url .= '?' . http_build_query($params);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Accept: application/json",
            "Authorization: Basic " . base64_encode(env('DID_SALE_SERVICE_KEY') . ':' . env('DID_SALE_SERVICE_TOKEN'))
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE); // Get HTTP response code
        curl_close($ch);

        if ($http_code == 200) {
            $response = json_decode($response, true);

            if (isset($response['status']) && $response['status']) {
                $dataFound = false; // Flag to check if any data is found for the country code

                foreach ($response['numbers'] as $row) {
                    if (empty($states) || in_array($row['state'], $states)) {
                        $area_code = substr($row['number'], 0, 3);
                        $temp = [
                            "<input type='checkbox' id='select_all_checkbox_" . $row['number'] . "' value='" . $row['number'] . "' data-ratecenter='" . $row['ratecenter'] . "' data-referenceid='" . $row['reference_id'] . "' data-state='" . $row['state'] . "' data-didtype='Metered' class='did_checkbox' /><label for='select_all_checkbox_" . $row['number'] . "'></label>",
                            $row['number'],
                            $area_code,
                            $row['state'],
                            "Metered"
                        ];
                        $result[] = $temp;
                        $dataFound = true; // Set flag to true as data is found
                    }
                }

                // if (!$dataFound) {
                //     // No data found for this country code, add placeholder entry
                //     $result[] = [
                //         "",
                //         'NA',
                //         $country_code,
                //         'NA',
                //         'NA'
                //     ];
                // }
            } else {
                Log::warning("Failed to fetch data for country code: $country_code. API message: " . ($response['message'] ?? 'No message'));
                // $result[] = [
                //     "",
                //     'NA',
                //     $country_code,
                //     'NA',
                //     'NA'
                // ];
            }
        } else {
            Log::error("Failed to fetch data from DID Sale API for country code: $country_code. HTTP Code: $http_code");
            // $result[] = [
            //     "",
            //     'NA',
            //     $country_code,
            //     'NA',
            //     'NA'
            // ];
        }
    }

    return $result;
}

    
    // private function getDidfromDidPlivo($request)
    // {
    //     Log::info('Reached', [$request->all()]);

    //     $result = [];
    //     // $number =  $request->data['phone'];
    //     $show = isset($request->data['show']) ? $request->data['show'] : 10;
    //     $country_codes = isset($request->data['country']) ? $request->data['country'] : ['1']; // Default to US if not set
    //     $number_type = isset($request->data['numberType']) ? $request->data['numberType'] : 'local';
    //     $states = isset($request->data['state_name']) ? (array)$request->data['state_name'] : []; // Ensure states is an array
    
    //     $totalToShow = $show; // Total number of entries to fetch
    
    //     foreach ($country_codes as $country_code) {
          
    //         $params = [
    //             'limit' => 1, // Adjust limit based on remaining entries to fetch
    //             'country_iso' => 'US',
    //             'type' => $number_type,
    //             'pattern' => $country_code,
    //         ];
    
    //         $database = "mysql_" . $request->auth->parent_id;
    //         $sms_setting = SmsProviders::on($database)->where("status", '1')->where('provider', 'plivo')->first();
    
    //         if ($sms_setting) {
    //             $auth_id = $sms_setting->auth_id;
    //             $api_key = $sms_setting->api_key;
    
    //             $client = new RestClient($auth_id, $api_key);
    //             $response = $client->phonenumbers->list($country_code, $params);
    //             Log::info('Reached', ['response'=>$response]);

    //             foreach ($response as $list) {
    //                 // Check state filter if provided
    //                 if (empty($states) || in_array($list->properties['region'], $states)) {
    //                     $no= $list->properties['number'];
    //                     $area_code = substr($no, 1, 3);
    //                     $temp = [
    //                         "<input type='checkbox' id='select_all_checkbox_" . $list->properties['number'] . "' value='" . $list->properties['number'] . "' data-ratecenter='" . $list->properties['rateCenter'] . "' data-referenceid='" . $list->properties['rateCenter'] . "' data-state='" . $list->properties['region'] . "' data-didtype='fixed' class='did_checkbox' /><label for='select_all_checkbox_" . $list->properties['number'] . "'></label>",
    //                         $list->properties['number'],
    //                         $area_code ,
    //                         $list->properties['region'],
    //                         "Metered"
    //                     ];
    //                     $result[] = $temp;

    //                 }
    //             }
    //         } else {
    //             Log::warning("SMS settings not found for provider Plivo in database $database");
    //         }
    //     }
    
    //     return $result;
    // }
    
    private function getDidfromDidPlivo($request)
    {
        Log::info('Reached', [$request->all()]);
    
        $result = [];
        $show = isset($request->data['show']) ? $request->data['show'] : 10;
        $country_codes = isset($request->data['country']) ? $request->data['country'] : ['1']; // Default to US if not set
        $number_type = isset($request->data['numberType']) ? $request->data['numberType'] : 'local';
        $states = isset($request->data['state_name']) ? (array)$request->data['state_name'] : []; // Ensure states is an array
    
        $processedCountryCodes = []; // Array to track processed country codes
    
        foreach ($country_codes as $country_code) {
            $params = [
                'limit' => $show, // Adjust limit based on the number of entries to fetch
                'country_iso' => 'US',
                'type' => $number_type,
                'pattern' => $country_code,
            ];
    
            $database = "mysql_" . $request->auth->parent_id;
            $sms_setting = SmsProviders::on($database)->where("status", '1')->where('provider', 'plivo')->first();
    
            if ($sms_setting) {
                $auth_id = $sms_setting->auth_id;
                $api_key = $sms_setting->api_key;
    
                $client = new RestClient($auth_id, $api_key);
                $response = $client->phonenumbers->list($country_code, $params);
                Log::info('Response from Plivo', ['response' => $response]);
    
                $dataFound = false; // Flag to check if any data is found for the country code
    
                foreach ($response as $list) {
                    // Check state filter if provided
                    if (empty($states) || in_array($list->properties['region'], $states)) {
                        $no = $list->properties['number'];
                        $area_code = substr($no, 1, 3);
                        $temp = [
                            "<input type='checkbox' id='select_all_checkbox_" . $list->properties['number'] . "' value='" . $list->properties['number'] . "' data-ratecenter='" . $list->properties['rateCenter'] . "' data-referenceid='" . $list->properties['rateCenter'] . "' data-state='" . $list->properties['region'] . "' data-didtype='fixed' class='did_checkbox' /><label for='select_all_checkbox_" . $list->properties['number'] . "'></label>",
                            $list->properties['number'],
                            $area_code,
                            $list->properties['region'],
                            "Metered"
                        ];
    
                        // Add to result only if not already processed
                        $uniqueKey = $country_code; // Unique key based on country code
    
                        if (!in_array($uniqueKey, $processedCountryCodes)) {
                            $result[] = $temp;
                            $processedCountryCodes[] = $uniqueKey; // Mark this country code as processed
                        }
    
                        $dataFound = true; // Set flag to true as data is found
                    }
                }
    
                if (!$dataFound && !in_array($country_code, $processedCountryCodes)) {
                    // No data found for this country code, add placeholder entry
                    // $result[] = [
                    //     "",
                    //     'NA',
                    //     $country_code, // Show the country code here
                    //     'NA',
                    //     'NA'
                    // ];
                    $processedCountryCodes[] = $country_code; // Mark this country code as processed
                }
            } else {
                Log::warning("SMS settings not found for provider Plivo in database $database");
    
                // Add a placeholder entry indicating no SMS settings found if not already added
                if (!in_array($country_code, $processedCountryCodes)) {
                    // $result[] = [
                    //     "",
                    //     'NA',
                    //     $country_code, // Show the country code here
                    //     'NA',
                    //     'NA'
                    // ];
                    $processedCountryCodes[] = $country_code; // Mark this country code as processed
                }
            }
        }
    
        return $result;
    }
    
    
    
    
    // private function getDidfromDidTelnyx($request)
    // {
    //     Log::info('Reached', [$request->all()]);
    
    //     $result = [];
    //     $uniqueResults = [];
    
    //     $numbers = isset($request->data['phone']) ? (array)$request->data['phone'] : [];
    //     $country_code = isset($request->data['country']) ? (array)$request->data['country'] : ['US'];
    //     $number_type = isset($request->data['numberType']) ? $request->data['numberType'] : 'local';
    //     $states = isset($request->data['state_name']) ? (array)$request->data['state_name'] : []; // Ensure states is an array
    
    //     $database = "mysql_" . $request->auth->parent_id;
    
    //     $sms_setting = SmsProviders::on($database)->where("status", '1')->where('provider', 'telnyx')->first();
    //     $api_key = $sms_setting->api_key;
    
    //     $telnyxApiEndpoint = 'https://api.telnyx.com/v2/available_phone_numbers';
    
    //     foreach ($numbers as $number) {
    //         foreach ($country_code as $code) { // Loop through each country code
    //             $filters = [
    //                 'country_code' => $code,
    //                 'best_effort' => true,
    //                 'limit' => 1,
    //                 'national_destination_code' => $number,
    //                 'phone_number_type' => $number_type
    //             ];
    
    //             $ch = curl_init($telnyxApiEndpoint . '?' . http_build_query(['filter' => $filters]));
    //             curl_setopt($ch, CURLOPT_HTTPHEADER, [
    //                 'Accept: application/json',
    //                 'Authorization: Bearer ' . $api_key,
    //             ]);
    
    //             curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    //             $response = curl_exec($ch);
    //             $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    //             curl_close($ch);
    
    //             if ($http_code == 200) {
    //                 $result_data = json_decode($response, true);
    
    //                 foreach ($result_data['data'] as $list) {
    //                     $phone_number = $list['phone_number'];
    //                     $stateName = ''; // Initialize stateName variable
    //                     $area_code = substr($phone_number, 2, 3);

    //                     foreach ($list['region_information'] as $region) {
    //                         if ($region['region_type'] == 'state') {
    //                             $rateCenter = $region['region_name'];
    //                             $stateCodeInfo = \App\Model\Master\AreaCodeList::where('state_code', $rateCenter)->first();
    
    //                             // Check if matching record is found
    //                             if ($stateCodeInfo) {
    //                                 $stateName = $stateCodeInfo->state_name;
    //                             } else {
    //                                 $stateName = $rateCenter; // Set a default value if no match is found
    //                             }
    //                         }
    //                     }
    
    //                     // Check if stateName matches any of the provided states
    //                     if (empty($states) || in_array($stateName, $states)) {
    //                         $type = $list['phone_number_type'];
    
    //                         $temp = [
    //                             "<input type='checkbox' id='select_all_checkbox_" . $phone_number . "' value='" . $phone_number . "' data-ratecenter='" . $rateCenter . "' data-referenceid='" . $rateCenter . "' data-state='" . $rateCenter . "' data-didtype='fixed' class='did_checkbox' /><label for='select_all_checkbox_" . $phone_number . "'></label>",
    //                             $phone_number,
    //                             $area_code,
    //                             $stateName, // Use stateName variable
    //                             $type,
    //                         ];
    
    //                         // Use a unique key to track entries and avoid duplicates
    //                         $uniqueKey = $phone_number . '_' . $rateCenter;
    //                         if (!isset($uniqueResults[$uniqueKey])) {
    //                             $uniqueResults[$uniqueKey] = $temp;
    //                         }
    //                     }
    //                 }
    //             } else {
    //                 Log::error("Failed to fetch data from Telnyx API for phone number $number. HTTP Code: $http_code");
    //             }
    //         }
    //     }
    
    //     // Convert unique results to indexed array
    //     $result = array_values($uniqueResults);
    
    //     return $result;
        
    // }
    
    
   
    private function getDidfromDidTelnyxn($request)
{
    Log::info('Reached', [$request->all()]);

    $result = [];
    $uniqueResults = [];
    $numbers = isset($request->data['phone']) ? (array)$request->data['phone'] : [];
    $country_code = isset($request->data['country']) ? (array)$request->data['country'] : ['US'];
    $number_type = isset($request->data['numberType']) ? $request->data['numberType'] : 'local';
    $states = isset($request->data['state_name']) ? (array)$request->data['state_name'] : []; // Ensure states is an array

    $database = "mysql_" . $request->auth->parent_id;

    $sms_setting = SmsProviders::on($database)->where("status", '1')->where('provider', 'telnyx')->first();
    if (!$sms_setting) {
        Log::warning("SMS settings not found for provider Telnyx in database $database");
        // Return placeholder entry if SMS settings are not found
        return [
            [
                "<input type='checkbox' id='select_all_checkbox_na' value='NA' class='did_checkbox' /><label for='select_all_checkbox_na'></label>",
                'NA',
                'NA',
                'NA',
                'NA'
            ]
        ];
    }
    
    $api_key = $sms_setting->api_key;
    $telnyxApiEndpoint = 'https://api.telnyx.com/v2/available_phone_numbers';

    foreach ($numbers as $number) {
        $dataFound = false; // Flag to check if any data is found for the current number

        foreach ($country_code as $code) { // Loop through each country code
            $filters = [
                'country_code' => $code,
                'best_effort' => true,
                'limit' => 1,
                'national_destination_code' => $number,
                'phone_number_type' => $number_type
            ];

            $ch = curl_init($telnyxApiEndpoint . '?' . http_build_query(['filter' => $filters]));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Accept: application/json',
                'Authorization: Bearer ' . $api_key,
            ]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($http_code == 200) {
                $result_data = json_decode($response, true);

                if (empty($result_data['data'])) {
                    continue; // Skip if no data for this filter
                }

                foreach ($result_data['data'] as $list) {
                    $phone_number = $list['phone_number'];
                    $stateName = ''; // Initialize stateName variable
                    $area_code = substr($phone_number, 2, 3);
                    $rateCenter = '';

                    foreach ($list['region_information'] as $region) {
                        if ($region['region_type'] == 'state') {
                            $rateCenter = $region['region_name'];
                            $stateCodeInfo = \App\Model\Master\AreaCodeList::where('state_code', $rateCenter)->first();

                            // Check if matching record is found
                            if ($stateCodeInfo) {
                                $stateName = $stateCodeInfo->state_name;
                            } else {
                                $stateName = $rateCenter; // Set a default value if no match is found
                            }
                        }
                    }

                    // Check if stateName matches any of the provided states
                    if (empty($states) || in_array($stateName, $states)) {
                        $type = $list['phone_number_type'];

                        $temp = [
                            "<input type='checkbox' id='select_all_checkbox_" . $phone_number . "' value='" . $phone_number . "' data-ratecenter='" . $rateCenter . "' data-referenceid='" . $rateCenter . "' data-state='" . $rateCenter . "' data-didtype='fixed' class='did_checkbox' /><label for='select_all_checkbox_" . $phone_number . "'></label>",
                            $phone_number,
                            $area_code,
                            $stateName, // Use stateName variable
                            $type,
                        ];

                        // Use a unique key to track entries and avoid duplicates
                        $uniqueKey = $phone_number . '_' . $rateCenter;
                        if (!isset($uniqueResults[$uniqueKey])) {
                            $uniqueResults[$uniqueKey] = $temp;
                            $dataFound = true; // Set flag to true as data is found
                        }
                    }
                }
            } else {
                Log::error("Failed to fetch data from Telnyx API for phone number $number. HTTP Code: $http_code");
            }
        }

        // Add placeholder entry if no data was found for this phone number
        if (!$dataFound) {
            $result[] = [
                "<input type='checkbox' id='select_all_checkbox_na_" . $number . "' value='NA' class='did_checkbox' /><label for='select_all_checkbox_na_" . $number . "'></label>",
                'NA',
                'NA',
                'NA',
                'NA'
            ];
        }
    }

    // Convert unique results to indexed array
    $result = array_values($uniqueResults);

    // Ensure that placeholder entry is returned if no valid data was found at all
    if (empty($result)) {
        return [
            [
                "",
                'NA',
                $number,
                'NA',
                'NA'
            ]
        ];
    }

    return $result;
}



private function getDidfromDidTelnyx($request)
{
    Log::info('Reached function', [$request->all()]);

    $result = [];
    $uniqueResults = [];
    $numbersWithData = [];

    $numbers = isset($request->data['phone']) ? (array)$request->data['phone'] : [];
    $country_codes = isset($request->data['country']) ? (array)$request->data['country'] : ['US'];
    $number_type = isset($request->data['numberType']) ? $request->data['numberType'] : 'local';
    $states = isset($request->data['state_name']) ? (array)$request->data['state_name'] : [];

    $database = "mysql_" . $request->auth->parent_id;

    $sms_setting = SmsProviders::on($database)->where("status", '1')->where('provider', 'telnyx')->first();
    if (!$sms_setting) {
        Log::warning("SMS settings not found for provider Telnyx in database $database");
        return [
            [
                "",
                'NA',
                'NA',
                'NA',
                'NA'
            ]
        ];
    }

    $api_key = $sms_setting->api_key;
    $telnyxApiEndpoint = 'https://api.telnyx.com/v2/available_phone_numbers';

    $client = new Client();
    $promises = [];

    foreach ($numbers as $number) {
        $cleanNumber = str_replace(['(', ')', '_', '-', ' '], '', $number);

        foreach ($country_codes as $code) {
            $filters = [
                'country_code' => $code,
                'best_effort' => true,
                'limit' => 1,
                'national_destination_code' => $cleanNumber,
                'phone_number_type' => $number_type
            ];

            $url = $telnyxApiEndpoint . '?' . http_build_query(['filter' => $filters]);
            $promises[$cleanNumber] = $client->requestAsync('GET', $url, [
                'headers' => [
                    'Accept' => 'application/json',
                    'Authorization' => 'Bearer ' . $api_key,
                ],
            ])->then(
                function ($response) use ($cleanNumber, &$uniqueResults, &$numbersWithData, $states) {
                    $result_data = json_decode($response->getBody()->getContents(), true);

                    if (empty($result_data['data'])) {
                        return;
                    }

                    foreach ($result_data['data'] as $list) {
                        $phone_number = $list['phone_number'];
                        $area_code = substr($phone_number, 2, 3);
                        $stateName = '';
                        $rateCenter = '';

                        foreach ($list['region_information'] as $region) {
                            if ($region['region_type'] == 'state') {
                                $rateCenter = $region['region_name'];
                                $stateCodeInfo = \App\Model\Master\AreaCodeList::where('state_code', $rateCenter)->first();

                                $stateName = $stateCodeInfo ? $stateCodeInfo->state_name : $rateCenter;
                            }
                        }

                        if (empty($states) || in_array($stateName, $states)) {
                            $type = $list['phone_number_type'];
                            $uniqueKey = $phone_number . '_' . $rateCenter;

                            if (!isset($uniqueResults[$uniqueKey])) {
                                $uniqueResults[$uniqueKey] = [
                                    "<input type='checkbox' id='select_all_checkbox_" . $phone_number . "' value='" . $phone_number . "' data-ratecenter='" . $rateCenter . "' data-referenceid='" . $rateCenter . "' data-state='" . $stateName . "' data-didtype='fixed' class='did_checkbox' /><label for='select_all_checkbox_" . $phone_number . "'></label>",
                                    $phone_number,
                                    $area_code,
                                    $stateName,
                                    $type,
                                ];
                                $numbersWithData[] = $cleanNumber;
                            }
                        }
                    }
                },
                function ($exception) use ($cleanNumber) {
                    Log::error('Failed to fetch data from Telnyx API for phone number ' . $cleanNumber, ['exception' => $exception->getMessage()]);
                }
            );
        }
    }

    // Wait for all asynchronous requests to complete
    // Promise\settle($promises)->wait();
    foreach ($promises as $promise) {
        try {
            $promise->wait();
        } catch (\Exception $e) {
            Log::error('Failed to fetch data from Telnyx API', ['exception' => $e->getMessage()]);
        }
    }

    // Add placeholder entry if no data was found for a particular number
    foreach ($numbers as $number) {
        $cleanNumber = str_replace(['(', ')', '_', '-', ' '], '', $number);
        if (!in_array($cleanNumber, $numbersWithData)) {
            // $result[] = [
            //     "",
            //     'NA',
            //     $cleanNumber ,
            //     'NA',
            //     'NA'
            // ];
        }
    }

    // Convert unique results to an indexed array
    $result = array_merge(array_values($uniqueResults), $result);

    return $result;
}


// private function getDidfromDidTwilio($request)
// {
//     Log::info('Reached function', [$request->all()]);

//     $result = [];
//     $uniqueResults = [];
//     $phoneNumbersWithNoData = [];

//     $numbers = isset($request->data['phone']) ? (array)$request->data['phone'] : [];
//     $show = 1;
//     $country_codes = isset($request->data['country']) ? (array)$request->data['country'] : ['US'];
//     $number_type = isset($request->data['numberType']) ? $request->data['numberType'] : 'local';
//     $states = isset($request->data['state']) ? (array)$request->data['state'] : [];

//     $database = "mysql_" . $request->auth->parent_id;

//     $sms_setting = SmsProviders::on($database)->where("status", '1')->where('provider', 'twilio')->first();
//     $sid = $sms_setting->auth_id;
//     $token = $sms_setting->api_key;

//     $twilio = new \Twilio\Rest\Client($sid, $token);

//     foreach ($numbers as $number) {
//         $number = str_replace(['(', ')', '_', '-', ' '], '', $number);
//         $dataFound = false; // Flag to check if any data is found for the current number

//         foreach ($country_codes as $country_code) {
//             Log::info('Processing Country Code', ['country_code' => $country_code, 'number' => $number]);

//             // Fetch available local phone numbers from Twilio
//             $local = $twilio->availablePhoneNumbers($country_code)->local->read(["areaCode" => $number], $show);

//             Log::info('Twilio Response', ['local' => $local]);

//             foreach ($local as $list) {
//                 $phone_number = $list->phoneNumber;
//                 $area_code = substr($phone_number, 2, 3);
//                 $stateName = '';

//                 $region = $list->region; // Adjust according to actual Twilio API response

//                 $stateCodeInfo = \App\Model\Master\AreaCodeList::where('state_name', $region)->first();

//                 if ($stateCodeInfo) {
//                     $stateName = $stateCodeInfo->state;
//                 } else {
//                     $stateName = $region; // Set a default value if no match is found
//                 }

//                 if (empty($states) || in_array($stateName, $states)) {
//                     $uniqueKey = $phone_number . '_' . $stateName;

//                     if (!isset($uniqueResults[$uniqueKey])) {
//                         $temp = [
//                             "<input type='checkbox' id='select_all_checkbox_" . $phone_number . "' value='" . $phone_number . "' data-ratecenter='" . $list->rateCenter . "' data-referenceid='" . $list->rateCenter . "' data-state='" . $stateName . "' data-didtype='fixed' class='did_checkbox' /><label for='select_all_checkbox_" . $phone_number . "'></label>",
//                             $phone_number,
//                             $area_code,
//                             $stateName,
//                             "Metered",
//                         ];

//                         $uniqueResults[$uniqueKey] = $temp;
//                         $dataFound = true; // Set flag to true as data is found
//                     }
//                 }
//             }
//         }

//         // Add placeholder entry if no data was found for this phone number
//         if (!$dataFound) {
//             $result[] = [
//                 "<input type='checkbox' id='select_all_checkbox_na_" . $number . "' value='NA' class='did_checkbox' /><label for='select_all_checkbox_na_" . $number . "'></label>",
//                 'NA',
//                 $number, // Display phone number in place of state name
//                 'NA',
//                 'NA'
//             ];
//         }
//     }

//     // Convert unique results to indexed array
//     $result = array_values($uniqueResults);

//     // Ensure that placeholder entry is returned if no valid data was found at all
//     if (empty($result) && !empty($phoneNumbersWithNoData)) {
//         $result = $phoneNumbersWithNoData;
//     }

//     return $result;
// }

// private function getDidfromDidTwilion($request)
// {
//     Log::info('Reached function', [$request->all()]);

//     $result = [];
//     $uniqueResults = [];
//     $placeholderNumbers = [];

//     $numbers = isset($request->data['phone']) ? (array)$request->data['phone'] : [];
//     $show = 1;
//     $country_codes = isset($request->data['country']) ? (array)$request->data['country'] : ['US'];
//     $number_type = isset($request->data['numberType']) ? $request->data['numberType'] : 'local';
//     $states = isset($request->data['state']) ? (array)$request->data['state'] : [];

//     $database = "mysql_" . $request->auth->parent_id;

//     $sms_setting = SmsProviders::on($database)->where("status", '1')->where('provider', 'twilio')->first();
//     $sid = $sms_setting->auth_id;
//     $token = $sms_setting->api_key;

//     $twilio = new \Twilio\Rest\Client($sid, $token);

//     foreach ($numbers as $number) {
//         $number = str_replace(['(', ')', '_', '-', ' '], '', $number);
//         $dataFound = false; // Flag to check if any data is found for the current number

//         foreach ($country_codes as $country_code) {
//             Log::info('Processing Country Code', ['country_code' => $country_code, 'number' => $number]);

//             // Fetch available local phone numbers from Twilio
//             $local = $twilio->availablePhoneNumbers($country_code)->local->read(["areaCode" => $number], $show);

//             Log::info('Twilio Response', ['local' => $local]);

//             foreach ($local as $list) {
//                 $phone_number = $list->phoneNumber;
//                 $area_code = substr($phone_number, 2, 3);
//                 $stateName = '';

//                 $region = $list->region; // Adjust according to actual Twilio API response

//                 $stateCodeInfo = \App\Model\Master\AreaCodeList::where('state_name', $region)->first();

//                 if ($stateCodeInfo) {
//                     $stateName = $stateCodeInfo->state;
//                 } else {
//                     $stateName = $region; // Set a default value if no match is found
//                 }

//                 if (empty($states) || in_array($stateName, $states)) {
//                     $uniqueKey = $number; // Use the phone number itself as the unique key

//                     if (!isset($uniqueResults[$uniqueKey])) {
//                         $temp = [
//                             "<input type='checkbox' id='select_all_checkbox_" . $phone_number . "' value='" . $phone_number . "' data-ratecenter='" . $list->rateCenter . "' data-referenceid='" . $list->rateCenter . "' data-state='" . $stateName . "' data-didtype='fixed' class='did_checkbox' /><label for='select_all_checkbox_" . $phone_number . "'></label>",
//                             $phone_number,
//                             $area_code,
//                             $stateName,
//                             "Metered",
//                         ];

//                         $uniqueResults[$uniqueKey] = $temp;
//                         $dataFound = true; // Set flag to true as data is found
//                     }
//                 }
//             }
//         }

//         // Add placeholder entry if no data was found for this phone number
//         if (!$dataFound) {
//             $placeholderNumbers[$number] = [
//                 "",
//                 'NA',
//                 $number, // Display phone number in place of area code
//                 'NA',
//                 'NA'
//             ];
//         }
//     }

//     // Convert unique results and placeholders to an indexed array
//     $result = array_merge(array_values($uniqueResults), array_values($placeholderNumbers));

//     return $result;
// }

private function getDidfromDidTwilio($request)
{
    Log::info('Reached function', [$request->all()]);

    $result = [];
    $uniqueResults = [];
    $validNumbers = [];  // Track numbers with valid data
    
    $numbers = isset($request->data['phone']) ? (array)$request->data['phone'] : [];
    $show = 1;
    $country_codes = isset($request->data['country']) ? (array)$request->data['country'] : ['US'];
    $number_type = isset($request->data['numberType']) ? $request->data['numberType'] : 'local';
    $states = isset($request->data['state']) ? (array)$request->data['state'] : [];
    
    $database = "mysql_" . $request->auth->parent_id;
    
    $sms_setting = SmsProviders::on($database)->where("status", '1')->where('provider', 'twilio')->first();
    $sid = $sms_setting->auth_id;
    $token = $sms_setting->api_key;
    
    $twilio = new \Twilio\Rest\Client($sid, $token);
    
    // Cache state code information to reduce repeated queries
    $stateCodeInfoCache = \App\Model\Master\AreaCodeList::all()->keyBy('state_name')->toArray();
    
    // Track processed area codes to avoid duplicates
    $processedAreaCodes = [];
    
    // Asynchronous requests array
    $promises = [];
    $client = new \GuzzleHttp\Client();
    
    foreach ($numbers as $number) {
        $cleanNumber = str_replace(['(', ')', '_', '-', ' '], '', $number);
        $validNumbers[$cleanNumber] = false;  // Assume no valid data initially
        
        foreach ($country_codes as $country_code) {
            $promises[] = $client->getAsync("https://api.twilio.com/2010-04-01/Accounts/{$sid}/AvailablePhoneNumbers/{$country_code}/Local.json?AreaCode={$cleanNumber}&PageSize={$show}", [
                'auth' => [$sid, $token]
            ])->then(
                function ($response) use ($cleanNumber, &$uniqueResults, &$validNumbers, $stateCodeInfoCache, $states, &$processedAreaCodes) {
                    $local = json_decode($response->getBody()->getContents())->available_phone_numbers;
                    
                    $hasValidData = false;

                    foreach ($local as $list) {
                        $phone_number = $list->phone_number;
                        $area_code = substr($phone_number, 2, 3);
                        $region = $list->region;
                        $stateName = $stateCodeInfoCache[$region]['state'] ?? $region;
                        
                        if (empty($states) || in_array($stateName, $states)) {
                            if (!isset($processedAreaCodes[$area_code])) { // Check if area code is already added
                                $uniqueKey = $phone_number;
                                
                                $temp = [
                                    "<input type='checkbox' id='select_all_checkbox_" . $phone_number . "' value='" . $phone_number . "' data-ratecenter='" . $list->rate_center . "' data-referenceid='" . $list->rate_center . "' data-state='" . $stateName . "' data-didtype='fixed' class='did_checkbox' /><label for='select_all_checkbox_" . $phone_number . "'></label>",
                                    $phone_number,
                                    $area_code,
                                    $stateName,
                                    "Metered",
                                ];
                                
                                $uniqueResults[$uniqueKey] = $temp;
                                $validNumbers[$cleanNumber] = true; // Mark number as having valid data
                                $processedAreaCodes[$area_code] = true; // Mark area code as processed

                                $hasValidData = true;
                            }
                        }
                    }
                },
                function ($exception) use ($cleanNumber) {
                    Log::error('Twilio API request failed', ['number' => $cleanNumber, 'exception' => $exception->getMessage()]);
                    // Keep as false; this failure is handled in the placeholder logic
                }
            );
        }
    }
    
    // Wait for all asynchronous requests to complete
    // \GuzzleHttp\Promise\settle($promises)->wait();
    foreach ($promises as $promise) {
        try {
            $promise->wait();
        } catch (\Exception $e) {
            Log::error('Failed to fetch data from twilio API', ['exception' => $e->getMessage()]);
        }
    }
    
    // Add placeholder entry for numbers that had no valid data
    foreach ($numbers as $number) {
        $cleanNumber = str_replace(['(', ')', '_', '-', ' '], '', $number);
        if (!$validNumbers[$cleanNumber]) {
            // $result[] = [
            //     "",
            //     'NA',
            //     $cleanNumber,
            //     'NA',
            //     'NA'
            // ];
        }
    }

    // Convert unique results to an indexed array
    $result = array_merge(array_values($uniqueResults), $result);
    
    return $result;
}





public function getDidListForAreaCode(Request $request) {


    $voipProvider = $request->data['voip_provider'] ?? null;

    if (!$voipProvider) {
        return response()->json(['error' => 'VoIP provider is not specified'], 400);
    }

    try {
        switch ($voipProvider) {
            case 'didforsale':
                $result = $this->getDidfromDidSale($request);
                break;
            case 'plivo':
                $result = $this->getDidfromDidPlivo($request);
                break;
            case 'telnyx':
                $result = $this->getDidfromDidTelnyx($request);
                break;
            case 'twilio':
                $result = $this->getDidfromDidTwilio($request);
                break;
            default:
                return response()->json(['error' => 'Invalid VoIP provider'], 400);
        }

        Log::info('result', ['result' => $result]);
        return response()->json($result);

    } catch (Exception $e) {
        Log::error('Error fetching DID list', ['exception' => $e->getMessage()]);
        return response()->json(['error' => 'An error occurred while fetching DID list'], 500);
    }
   // return response()->json($response);
}
//buy did for sale
public function buySaveDidAreacode(Request $request) {


     $voipProvider = $request->data['voip_provider'] ?? null;

     if (!$voipProvider) {
         return response()->json(['error' => 'VoIP provider is not specified'], 400);
     }
 
     try {
         switch ($voipProvider) {
             case 'didforsale':
                 $result = $this->buySaveDid($request);
                 break;
             case 'plivo':
                 $result = $this->buySaveDidPlivo($request);
                 break;
             case 'telnyx':
                 $result = $this->buySaveDidTelnyx($request);
                 break;
             case 'twilio':
                 $result = $this->buySaveDidTwilio($request);
                 break;
             default:
                 return response()->json(['error' => 'Invalid VoIP provider'], 400);
         }
 
         Log::info('result', ['result' => $result]);
         return response()->json($result);
 
     } catch (Exception $e) {
         Log::error('Error fetching DID list', ['exception' => $e->getMessage()]);
         return response()->json(['error' => 'An error occurred while fetching DID list'], 500);
     }
    // return response()->json($response);
}


function buySaveDid($request)
{ 
    Log::info('reached',[$request->all()]);
    foreach($request->data['number'] as $objNumber)
    {
        $objNumberDecoded = json_decode($objNumber);
        $result = $this->buyDidFromSale($request->data['country_code'], $objNumberDecoded, $request);
        // if($result['status'])
        // {
            $this->saveDId($request, $objNumberDecoded->value);
        // }

    }

    return array(
         'success' => 'true',
         'message' => 'Phone Number has been added successfully.',
         'data' => []
     );
}
private function buyDidFromSale($countryCode, $objNumber, $request)
{
    // $url = env('DID_SALE_API_URL') . "products/BuyDID?ratecenter=$objNumber->ratecenter&state=$objNumber->state&did=$objNumber->value&reference_id=$objNumber->referenceid&didtype=metered";

    // $ch = curl_init();
    // curl_setopt($ch, CURLOPT_URL, $url);
    // curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
    // curl_setopt($ch, CURLOPT_HEADER, FALSE);
    // curl_setopt($ch, CURLOPT_HTTPHEADER, array("Accept: application/json","Authorization: Basic ".base64_encode(env('DID_SALE_SERVICE_KEY').':'.env('DID_SALE_SERVICE_TOKEN'))));
    // $response = curl_exec($ch);
    // $response = json_decode($response, 1);
    // if (!is_string($objNumber->value)) {
    //     Log::error('object is not a string', ['$objNumber->value' => $$objNumber->value]);
    // }
    // $this->configDidToIp($countryCode.$objNumber->value, $request);
    $this->configDidToIp(array_map(function($code) use ($objNumber) {
        return $code . $objNumber->value;
    }, $countryCode), $request);

    //return $response;
}


private function saveDId($request, $number, $provider = 1)
{
    $country_codes = $request->data['country_code']; // Use the country_code array

    foreach ($country_codes as $code) {
        $cliMaster = $number;
        $cliClient = $number;

        // Check if the CLI already exists in the master database
        $existsInMaster = DB::connection('master')
            ->table('did')
            ->where('cli', $cliMaster)
            ->exists();

        if (!$existsInMaster) {
            $dataMaster = [
                'parent_id' => $request->auth->parent_id,
                'user_id' => $request->id,
                'cli' => $cliMaster,
                'area_code' => substr($number, 0, 3),
                'country_code' => "+" . $code,
                'provider' => $provider,
                'voip_provider' => $request->data['voip_provider'],
            ];

            $queryMaster = "INSERT INTO did (parent_id,user_id,cli,area_code,country_code,provider,voip_provider) "
                         . "VALUES (:parent_id,:user_id,:cli, :area_code, :country_code, :provider, :voip_provider)";
            DB::connection('master')->update($queryMaster, $dataMaster);
        }

        // Check if the CLI already exists in the client's database
        $existsInClient = DB::connection('mysql_' . $request->auth->parent_id)
            ->table('did')
            ->where('cli', $cliClient)
            ->exists();

        if (!$existsInClient) {
            $dataClient = [
                'cli' => $cliClient,
                'area_code' => substr($number, 0, 3),
                'voip_provider' => $request->data['voip_provider'],
            ];

            $queryClient = "INSERT INTO did (cli,area_code,voip_provider) "
                         . "VALUES (:cli, :area_code, :voip_provider)";
            DB::connection('mysql_' . $request->auth->parent_id)->update($queryClient, $dataClient);
        }
    }
}


private function configDidToIp($did, $request)
{
    $ip = $this->getAsteriskServerDetails($request);
    $result= [];
    // $url = env('DID_SALE_API_URL') . "products/ManageDID/config1";
    // $ch = curl_init();
    // curl_setopt($ch, CURLOPT_URL, $url);
    // curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
    // curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['did' => [$did], 'ip1' => $ip, 'ip1_port' => "5060"]));
    // curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
    // curl_setopt($ch, CURLOPT_HTTPHEADER, array("Accept: application/json","Authorization: Basic ".base64_encode(env('DID_SALE_SERVICE_KEY').':'.env('DID_SALE_SERVICE_TOKEN'))));
    // $result = curl_exec($ch);
    // $result = json_decode($result, 1);

    return $result;
}
private function getAsteriskServerDetails($request)
{
    $hostIp = '';
    $sql = "select ip_address from client_server where client_id ={$request->auth->parent_id}  Limit 1 ";
    $record = DB::connection('master')->select($sql);
    $data = (array)$record;
    if (isset($data[0]->ip_address))
    {
        $sql = "select host from asterisk_server where id = ".$data[0]->ip_address."  Limit 1 ";
        $record = DB::connection('master')->select($sql);
        $data = (array)$record;
        if (isset($data[0]->host))
        {
            $hostIp = $data[0]->host;
        }
    }
    return $hostIp;
}
//buy plivo
function buySaveDidPlivo($request)
{
    foreach($request->data['number'] as $objNumber)
    {
        $objNumberDecoded = json_decode($objNumber);
        $result = $this->buyDidFromPlivo($request->data['country_code'], $objNumberDecoded, $request);
      /*  if($result['status'])
        {
        }*/

            $this->saveDIdPLIVO($request, $objNumberDecoded->value);
    }

    return array(
         'success' => 'true',
         'message' => 'Phone Number has been added successfully.',
         'data' => []
     );
}
private function buyDidFromPlivo($countryCode, $objNumber, $request)
    {

        // $sms_setting = SmsProviders::on('mysql_' . $request->auth->parent_id)->where("status",'1')->where('provider','plivo')->get()->first();

        // $auth_id = $sms_setting->auth_id;
        // $api_key = $sms_setting->api_key;


        // $auth_id = $sms_setting->auth_id;
        // $api_key = $sms_setting->api_key;

        // $client = new RestClient($auth_id,$api_key);

        // $response = $client->phonenumbers->buy($objNumber->value);
        // $this->configDidToIp($countryCode.$objNumber->value, $request);
        $this->configDidToIp(array_map(function($code) use ($objNumber) {
            return $code . $objNumber->value;
        }, $countryCode), $request);
       // return $response;
    }
    private function saveDIdPLIVO($request, $number, $provider = 1)
    {
        $country_codes = $request->data['country_code']; // Use the country_code array
    
        foreach ($country_codes as $code) {
            $cliMaster = $number;
            $cliClient =  $number;
    
            // Check if the CLI already exists in the master database
            $existsInMaster = DB::connection('master')
                ->table('did')
                ->where('cli', $cliMaster)
                ->exists();
    
            if (!$existsInMaster) {
                $dataMaster = [
                    'parent_id' => $request->auth->parent_id,
                    'user_id' => $request->id,
                    'cli' => $cliMaster,
                    'area_code' => substr($number, 1, 3),
                    'country_code' => "+" . $code,
                    'provider' => $provider,
                    'voip_provider' => $request->data['voip_provider'],
                ];
    
                $queryMaster = "INSERT INTO did (parent_id,user_id,cli,area_code,country_code,provider,voip_provider) "
                             . "VALUES (:parent_id,:user_id,:cli, :area_code, :country_code, :provider, :voip_provider)";
                DB::connection('master')->update($queryMaster, $dataMaster);
            }
    
            // Check if the CLI already exists in the client's database
            $existsInClient = DB::connection('mysql_' . $request->auth->parent_id)
                ->table('did')
                ->where('cli', $cliClient)
                ->exists();
    
            if (!$existsInClient) {
                $dataClient = [
                    'cli' => $cliClient,
                    'area_code' => substr($number, 1, 3),
                    'voip_provider' => $request->data['voip_provider'],
                ];
    
                $queryClient = "INSERT INTO did (cli,area_code,voip_provider) "
                             . "VALUES (:cli, :area_code, :voip_provider)";
                DB::connection('mysql_' . $request->auth->parent_id)->update($queryClient, $dataClient);
            }
        }
    }
    //buy telnyx
    function buySaveDidTelnyx($request)
    {
        foreach($request->data['number'] as $objNumber)
        {
            $objNumberDecoded = json_decode($objNumber);
            $sms_setting = SmsProviders::on('mysql_' . $request->auth->parent_id)->where("status",'1')->where('provider','telnyx')->get()->first();
            $phone_number = $objNumberDecoded->value;

            $number = ["phone_numbers" => [
                ["phone_number" => $phone_number]
            ]
        ];

            $telnyxApiKey = $sms_setting->api_key;

        //check balance

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://api.telnyx.com/v2/balance');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer '.$telnyxApiKey,
        ]);

        $result = curl_exec($ch);
        curl_close($ch);
        $res = json_decode($result);
        $balance =  $res->data->balance;
        //$balance =0.01;

        if($balance >= 0.20)
        {
           
        }
        else
        {
            return array(
                'success' => 'false',
                'message' => 'Telnyx Balance is Low',
                'data' => array()
            );
        }

        
        //echo $hell;die;


        // $ch = curl_init();
        // $send = json_encode($number);
        // curl_setopt($ch, CURLOPT_URL, 'https://api.telnyx.com/v2/number_orders');
        // curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        // curl_setopt($ch, CURLOPT_POST, 1);
        // curl_setopt($ch, CURLOPT_POSTFIELDS, $send);

        // $headers = array();
        // $headers[] = 'Content-Type: application/json';
        // $headers[] = 'Accept: application/json';
        // $headers[] = 'Authorization: Bearer '. $telnyxApiKey;

        // curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        // $response = curl_exec($ch);

        $this->saveDIdTelnyx($request, $objNumberDecoded->value);
    }

        return array(
             'success' => 'true',
             'message' => 'Phone Number has been added successfully.',
             'data' => []
         );
    }
    private function saveDIdTelnyx($request, $number, $provider = 1)
    {
        $country_codes = $request->data['country_code']; // Use the country_code array
    
        foreach ($country_codes as $code) {
            $cliMaster = $number;
            $cliClient =  $number;
    
            // Check if the CLI already exists in the master database
            $existsInMaster = DB::connection('master')
                ->table('did')
                ->where('cli', $cliMaster)
                ->exists();
    
            if (!$existsInMaster) {
                $dataMaster = [
                    'parent_id' => $request->auth->parent_id,
                    'user_id' => $request->id,
                    'cli' => $cliMaster,
                    'area_code' => substr($number, 2, 3),
                    'country_code' => "+" . $code,
                    'provider' => $provider,
                    'voip_provider' => $request->data['voip_provider'],
                ];
    
                $queryMaster = "INSERT INTO did (parent_id,user_id,cli,area_code,country_code,provider,voip_provider) "
                             . "VALUES (:parent_id,:user_id, :cli, :area_code, :country_code, :provider, :voip_provider)";
                DB::connection('master')->update($queryMaster, $dataMaster);
            }
    
            // Check if the CLI already exists in the client's database
            $existsInClient = DB::connection('mysql_' . $request->auth->parent_id)
                ->table('did')
                ->where('cli', $cliClient)
                ->exists();
    
            if (!$existsInClient) {
                $dataClient = [
                    'cli' => $cliClient,
                    'area_code' => substr($number, 2, 3),
                    'voip_provider' => $request->data['voip_provider'],
                ];
    
                $queryClient = "INSERT INTO did (cli,area_code,voip_provider) "
                             . "VALUES (:cli, :area_code, :voip_provider)";
                DB::connection('mysql_' . $request->auth->parent_id)->update($queryClient, $dataClient);
            }
        }
    }
    //buy twilio
  
    function buySaveDidTwilio($request)
    {
        foreach($request->data['number'] as $objNumber)
        {
            $objNumberDecoded = json_decode($objNumber);
            $sms_setting = SmsProviders::on('mysql_' . $request->auth->parent_id)->where("status",'1')->where('provider','twilio')->get()->first();
            $phone_number = $objNumberDecoded->value;

            $number = ["phone_numbers" => [
                ["phone_number" => $phone_number]
            ]
        ];

            $twilioApiKey = $sms_setting->api_key;

    

        $sid = $sms_setting->auth_id;
        $token = $sms_setting->api_key;

        // $twilio = new \Twilio\Rest\Client($sid, $token);
        // $incoming_phone_number = $twilio->incomingPhoneNumbers
        //                         ->create(["phoneNumber" => $phone_number]);

//print($incoming_phone_number->sid);

        $this->saveDIdTwilio($request, $objNumberDecoded->value);
    }

        return array(
             'success' => 'true',
             'message' => 'Phone Number has been added successfully.',
             'data' => []
         );
    }
    private function saveDIdTwilio($request, $number, $provider = 1){
    $country_codes = $request->data['country_code']; // Use the country_code array
    
    foreach ($country_codes as $code) {
        $cliMaster = $number;
        $cliClient =  $number;

        // Check if the CLI already exists in the master database
        $existsInMaster = DB::connection('master')
            ->table('did')
            ->where('cli', $cliMaster)
            ->exists();

        if (!$existsInMaster) {
            $dataMaster = [
                'parent_id' => $request->auth->parent_id,
                'user_id'=>$request->id,
                'cli' => $cliMaster,
                'area_code' => substr($number, 2, 3),
                'country_code' => "+" . $code,
                'provider' => $provider,
                'voip_provider' => $request->data['voip_provider'],
            ];

            $queryMaster = "INSERT INTO did (parent_id,user_id,cli,area_code,country_code,provider,voip_provider) "
                         . "VALUES (:parent_id,:user_id, :cli, :area_code, :country_code, :provider, :voip_provider)";
            DB::connection('master')->update($queryMaster, $dataMaster);
        }

        // Check if the CLI already exists in the client's database
        $existsInClient = DB::connection('mysql_' . $request->auth->parent_id)
            ->table('did')
            ->where('cli', $cliClient)
            ->exists();

        if (!$existsInClient) {
            $dataClient = [
                'cli' => $cliClient,
                'area_code' => substr($number, 2, 3),
                'voip_provider' => $request->data['voip_provider'],
            ];

            $queryClient = "INSERT INTO did (cli,area_code,voip_provider) "
                         . "VALUES (:cli, :area_code, :voip_provider)";
            DB::connection('mysql_' . $request->auth->parent_id)->update($queryClient, $dataClient);
        }
    }

}
}
    

