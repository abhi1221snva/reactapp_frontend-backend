<?php

namespace App\Http\Controllers;
use App\Exceptions\RenderableException;
use App\Model\Client\Campaign;
use App\Model\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

use App\Model\Client\ExtensionLive;
use App\Model\Dialer;
use Illuminate\Support\Facades\DB;



class CallPredictiveDialController extends Controller
{
	private $request;
	public function __construct(Request $request, Dialer $dialer)
	{
		$this->request = $request;
		$this->model = $dialer;
	}

	public function index(Request $request)
	{
		die;
		$data = array();
		$client_id = $request->client_id;
		$live_extensions = ExtensionLive::on("mysql_".$client_id)->where('status',0)->get()->all();

		if(!empty($live_extensions))
		{
			$data['total_free_live_extensions'] = count($live_extensions);
			foreach($live_extensions as $key =>$extension)
			{
				$data['live_extension'][$key]['extension'] = $extension->extension;
				$data['live_extension'][$key]['campaign_id'] = $extension->campaign_id;

				$campaign_list = Campaign::on("mysql_".$client_id)->where('id',$extension->campaign_id)->get()->first();

				$data['live_extension'][$key]['campaign_title'] = $campaign_list->title;
				$data['live_extension'][$key]['campaign_dial_mode'] = $campaign_list->dial_mode;
				$data['live_extension'][$key]['campaign_custom_caller_id'] = $campaign_list->custom_caller_id;
				$data['live_extension'][$key]['campaign_hopper_mode'] = $campaign_list->hopper_mode;


				$serverSql = "SELECT asterisk_server.id,host as ip_address,detail,domain FROM client_server Left join asterisk_server on asterisk_server.id = client_server.ip_address WHERE client_server.client_id = :client_id";
            	$serverList = DB::connection('master')->select($serverSql, array('client_id' => $client_id));
            	$serverListResponse = (array)$serverList;
            	$response['serverList'] = $serverListResponse;

            	$asterisk_server_id = $response['serverList'][0]->id;

            	$data['live_extension'][$key]['asterisk_server_id'] = $response['serverList'][0]->id;

				//echo "<pre>";print_r($response);die;


				$addResponse = $this->model->addLeadToExtensionLive(
                    $extension->campaign_id,
                    $campaign_list->hopper_mode,
                    $extension->extension,
                    $asterisk_server_id,
                    $client_id
                );
			}


			$response = array('status'=>'true','data'=>$data,'message'=>'Predictive Dial run successfully');
		}

		else
		{
			$response = array('status'=>'false','data'=>$data,'message'=>'No Extensions are free or live');
		}
		
		echo "<pre>";print_r($response);die;
	}
}
