<?php

namespace App\Http\Controllers;
use App\Model\Api;
use Illuminate\Http\Request;
class GoogleCalendarController extends Controller
{
   public function connect()

{

        $client = GoogleCalendar::getClient();

        echo "<pre>";print_r($client);die;

        $authUrl = $client->createAuthUrl();

        return redirect($authUrl);

 }
}
