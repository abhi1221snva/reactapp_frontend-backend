<?php

namespace App\Http\Controllers;

use App\Exceptions\RenderableException;
use App\Model\Client\Campaign;
use App\Model\Client\SmtpSetting;
use App\Model\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Model\Report;


class CallLeadController extends Controller
{
	private $request;

    public function __construct(Request $request, Report $report)
    {
        $this->request = $request;
        $this->model = $report;
    }


	public function callLead(Request $request)
	{
		$number = $request->number;
		$extension = $request->extension;

        $intWebPhoneSetting = DialerController::getWebPhonestatus($request->auth->id, $request->auth->parent_id);
        if ($intWebPhoneSetting == 1) {
            $extension = $request->auth->alt_extension;
        }

        $curl = curl_init();
		curl_setopt_array($curl, array(
			CURLOPT_URL => env("PORTAL_NAME")."click/click2call.php?outboundnum=".$number."&internalnum=".$extension,
			CURLOPT_RETURNTRANSFER => true,
    		CURLOPT_ENCODING => "",
    		CURLOPT_TIMEOUT => 30000,
    		CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    		CURLOPT_CUSTOMREQUEST => "POST",
    		CURLOPT_HTTPHEADER => array(
    			'Content-Type: application/json',
    		),
    	));

    	$response = curl_exec($curl);
    	$err = curl_error($curl);
        Log::debug("ErrorCallingLead.callLead", [$err,$curl]);
    	curl_close($curl);
    	if ($err)
    	{
    		return "cURL Error #:" . $err;
		}
		else
		{
			$result =  str_replace("Asterisk Call Manager/7.0.1","",$response);
			$final_result =  str_replace("Response: ","",$result);
			return json_encode($final_result);
		}
	}

	public function getLiveCallActivity()
	{
		$response = $this->model->getLiveCallActivity($this->request);
        return response()->json($response);
	}


}
