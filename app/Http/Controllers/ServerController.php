<?php

namespace App\Http\Controllers;

use App\Model\Master\AsteriskServer;
use App\Model\Master\Client;
use App\Model\Master\ClientServers;
use Illuminate\Http\Request;

class ServerController extends Controller
{
    public function asteriskServers()
    {
        return $this->successResponse("Asterisk Servers", AsteriskServer::list());
    }

    public function clientServers(Request $request)
    {
        $serverIds = ClientServers::where(["client_id" => $request->auth->parent_id])->pluck("server_id")->all();
        $asteriskServerList = [];
        $servers = AsteriskServer::whereIn('id', $serverIds)->get()->all();
        foreach ( $servers as $server ) {
            array_push($asteriskServerList, $server->toArray());
        }
        return $this->successResponse("Client Asterisk Servers", $asteriskServerList);
    }
}
