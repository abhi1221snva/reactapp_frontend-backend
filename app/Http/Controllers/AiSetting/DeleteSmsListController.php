<?php

namespace App\Http\Controllers\AiSetting;
use Session;
use App\Helper\Helper;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\MessageBag;
use Illuminate\Support\Facades\Config;
use App\Http\Controllers\Controller;
use GuzzleHttp\Client;

class DeleteSmsListController extends Controller
{

    public function index(Request $request)
    {
        $cli = $request->input('cli');
        $number = $request->input('number');
        $limit = $request->input('limit') ?: 20;

        // Properly interpolate variables in the URL
        $url = "https://sms-6qpk.onrender.com/sms/list?cli={$cli}&number={$number}&limit={$limit}&api-key=sms-G8nu4BQI_IDrWEgkE9vQ2Je-zb3wX-YDnD_-ZbWHb8i0TnIOWgg7NzsiIzi8rw7p";
        Log::info('Reached', ['url' => $url]);
        
        $cli_list = [];
    
        try {
            $client = new Client();
            $response = $client->request('GET', $url, [
                'headers' => [
                    'accept' => 'application/json',
                    'x-api-key' => 'sms-G8nu4BQI_IDrWEgkE9vQ2Je-zb3wX-YDnD_-ZbWHb8i0TnIOWgg7NzsiIzi8rw7p',
                ]
            ]);
            
            $data = json_decode($response->getBody(), true);
            
            return response()->json($data);
        } catch (RequestException $ex) {
            Log::error("API Request Exception: ", ['message' => $ex->getMessage()]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch data from the API.',
                'error' => $ex->getMessage()
            ], 500);
        }
    }
  

    // public function delete(Request $request)
    // {
    //     $cli = $request->input('cli');
    //     $number = $request->input('number');

    //     // Properly interpolate variables in the URL
    //     $url = "https://sms-6qpk.onrender.com/sms/delete?cli={$cli}&number={$number}5%20&api-key=sms-G8nu4BQI_IDrWEgkE9vQ2Je-zb3wX-YDnD_-ZbWHb8i0TnIOWgg7NzsiIzi8rw7p";
    //     Log::info('Reached  delete url', ['url' => $url]);
        
    //     $cli_list = [];
    
    //     try {
    //         $client = new Client();
    //         $response = $client->request('DELETE', $url, [
    //             'headers' => [
    //                 'accept' => 'application/json',
    //                 'x-api-key' => 'sms-G8nu4BQI_IDrWEgkE9vQ2Je-zb3wX-YDnD_-ZbWHb8i0TnIOWgg7NzsiIzi8rw7p',
    //             ]
    //         ]);
            
    //         $data = json_decode($response->getBody(), true);
    //         Log::info('API Response', ['data' => $data]);

    //         return response()->json($data);
    //     } catch (RequestException $ex) {
    //         Log::error("API Request Exception: ", ['message' => $ex->getMessage()]);
            
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Failed to fetch data from the API.',
    //             'error' => $ex->getMessage()
    //         ], 500);
    //     }
    // }
    public function delete(Request $request)
{
    $cli = $request->input('cli');
    $number = $request->input('number');

    // Prepare the URL
    $url = "https://sms-6qpk.onrender.com/sms/delete?cli={$cli}&number={$number}&api-key=sms-G8nu4BQI_IDrWEgkE9vQ2Je-zb3wX-YDnD_-ZbWHb8i0TnIOWgg7NzsiIzi8rw7p";
    Log::info('Reached delete URL', ['url' => $url]);

    // Initialize cURL session
    $ch = curl_init();

    // Set cURL options
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'accept: application/json',
        'x-api-key: sms-G8nu4BQI_IDrWEgkE9vQ2Je-zb3wX-YDnD_-ZbWHb8i0TnIOWgg7NzsiIzi8rw7p',
    ]);

    // Execute the cURL request
    $response = curl_exec($ch);

    // Check for cURL errors
    if ($response === false) {
        $error = curl_error($ch);
        Log::error("cURL Error: ", ['message' => $error]);

        curl_close($ch);

        return response()->json([
            'success' => false,
            'message' => 'Failed to fetch data from the API.',
            'error' => $error
        ], 500);
    }

    // Close the cURL session
    curl_close($ch);

    // Decode the response
    $data = json_decode($response, true);

    Log::info('API Response', ['data' => $data]);

    // Return the response
    return response()->json($data);
}

}