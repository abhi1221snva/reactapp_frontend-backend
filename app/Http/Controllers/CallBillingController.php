<?php

namespace App\Http\Controllers;

use App\Services\CallService;
use Illuminate\Http\Request;

class CallBillingController extends Controller
{
    public function prepareBill(Request $request)
    {
        $intExtensionNo  = $request->input('extension_no');
        $intCallDuration = $request->input('call_duration'); // call_duration in minutes
        $intPhoneNo      = $request->input('phone_no'); // phone_no of person whom called
        $intCdrId        = $request->input('cdr_id');
        $strToken        = $request->input('token');
        $intClientId     = $request->input('client_id'); // parent_id for tenant isolation

        $objCallService = new CallService($intExtensionNo, $intPhoneNo, $intCdrId, $intClientId);
        $response = $objCallService->chargeForCall($intCallDuration, $strToken);
        if ($response) {
            return $this->successResponse("Call charged successfully.", []);
        } else {
            return $this->failResponse("Failed to charge.", []);
        }
    }
}
