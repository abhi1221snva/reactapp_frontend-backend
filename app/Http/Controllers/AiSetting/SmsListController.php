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

class SmsListController extends Controller
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
}