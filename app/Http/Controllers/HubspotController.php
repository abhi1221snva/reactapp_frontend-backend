<?php

namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Model\Client\CrmLists;


class HubspotController extends Controller
{

    public function loginHubspot(Request $request)
    {
        $crmLists = CrmLists::on("mysql_" . $request->auth->parent_id)->where('title_url','hubspot')->get()->first();
        return $crmLists;

    }
    public function lists(Request $request)
    {
        $result_hubspot = $this->loginHubspot($request);
        $api_url = $result_hubspot->url;
        $api_key = $result_hubspot->key;

        $url = $api_url.'contacts/v1/lists';
        $hapikey = $api_key;
        $ch = curl_init($url);
        $headers = array('Content-Type: application/json','Authorization: Bearer '.$hapikey);

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $response = curl_exec($ch);
        if(curl_errno($ch)){
            throw new Exception(curl_error($ch));
        }

        curl_close($ch);
        $lists_data = json_decode($response, true, JSON_UNESCAPED_SLASHES);

        if(!empty($lists_data['lists']))
        {
        return $this->successResponse("Hubspot lists", $lists_data['lists']);

        }
        else
        {
            return $this->failResponse("List Not Found", [$lists_data]);
        }
    }

    public function getContactInAList(Request $request, int $id)
    {
        $result_hubspot = $this->loginHubspot($request);
        $api_url = $result_hubspot->url;
        $api_key = $result_hubspot->key;

        $url = $api_url.'contacts/v1/lists/'.$id.'/contacts/all?property=phone&property=firstname&property=lastname&property=email&property=createdate&property=createdate';
        $hapikey = $api_key;
        $ch = curl_init($url);
        $headers = array('Content-Type: application/json','Authorization: Bearer '.$hapikey);

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $response = curl_exec($ch);
        if(curl_errno($ch)){
            throw new Exception(curl_error($ch));
        }

        curl_close($ch);
        $lists_data = json_decode($response, true, JSON_UNESCAPED_SLASHES);

        if(!empty($lists_data['contacts']))
        {
        return $this->successResponse("Hubspot lists", $lists_data['contacts']);

        }
        else
        {
            return $this->failResponse("List Not Found", [$lists_data]);
        }
    }
}
