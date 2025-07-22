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

class UsersCliNameController extends Controller
{

    public function index()
    {
        $url = 'https://sms-6qpk.onrender.com/sms/user-clis?api-key=sms-G8nu4BQI_IDrWEgkE9vQ2Je-zb3wX-YDnD_-ZbWHb8i0TnIOWgg7NzsiIzi8rw7p';
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
            //dd($data); // Dump and die to inspect the response structure
            return $data;
        } catch (RequestException $ex) {
            Log::error("API Request Exception: ", ['message' => $ex->getMessage()]);
        }
    }
  

 }